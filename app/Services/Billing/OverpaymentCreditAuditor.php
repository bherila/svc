<?php

namespace App\Services\Billing;

use App\Support\Billing\CreditPoolPartition;
use App\Support\Billing\InvoicePaymentStatus;
use App\Support\Billing\InvoiceStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Compare the overpayment credit each company was funded with to what its
 * issued invoices have spent, per workspace, company and currency.
 *
 * Read-only, and a diagnostic rather than a gate. The ledger that `issue()`
 * spends from clamps what remains at zero, so "no credit available" says
 * nothing about whether credit was ever spent twice; this asks the question
 * directly. Funded and consumed are summed in integer minor units, a deficit
 * in one partition is never offset by a surplus in another, and a partition
 * holding data the ledger cannot read is reported as unevaluable - never as
 * zero - with the reasons counted.
 *
 * A deficit is a *current* state that needs investigating. It does not say
 * how it arose: a double spend, an import, a refund after the credit was used,
 * or a manual edit all look the same from here.
 */
final class OverpaymentCreditAuditor
{
    /** Invoices per batch; each batch costs three queries. */
    private const BATCH = 500;

    /**
     * Every pool with funding, spending or a problem.
     *
     * One read-only transaction around the whole scan. The invoices, their
     * payments and their credit lines are separate queries, and as separate
     * autocommit statements a write committing between them - an issue, a
     * refund, a void - would be seen by the later reads and not the earlier,
     * reporting a balance that existed at no moment. Inside one transaction
     * REPEATABLE READ fixes a snapshot at the first read and every batch reads
     * the same state. Nothing is written, so it commits nothing.
     *
     * @return list<CreditPoolPartition>
     */
    public function partitions(): array
    {
        return DB::transaction(fn (): array => $this->scan());
    }

    /** @return list<CreditPoolPartition> */
    private function scan(): array
    {
        /** @var array<string, CreditPoolPartition> $partitions */
        $partitions = [];
        $partition = function (int $workspaceId, ?int $companyId, string $currency) use (&$partitions): CreditPoolPartition {
            $key = $workspaceId.':'.($companyId ?? 'none').':'.$currency;

            return $partitions[$key] ??= new CreditPoolPartition($workspaceId, $companyId, $currency);
        };
        // Ownership of each invoice's company, resolved once. A company that is
        // missing or belongs to another workspace cannot own a pool.
        $companyWorkspace = [];
        foreach (DB::table('client_companies')->get(['id', 'workspace_id']) as $company) {
            $companyWorkspace[self::key($company->id)] = self::key($company->workspace_id);
        }

        // Funding: settled money over each live invoice's total. Per invoice,
        // because overpayment is `max(0, settled - total)` of *that* invoice.
        // Loaded a bounded batch at a time - the invoices, then that batch's
        // payments and credit lines in one query each - so the query count
        // grows with the number of batches, not of invoices.
        $batches = DB::table('client_invoices')
            ->select(['id', 'workspace_id', 'client_company_id', 'currency', 'status', 'total_amount'])
            ->orderBy('id')
            ->lazy(self::BATCH)
            ->chunk(self::BATCH);
        foreach ($batches as $batch) {
            $ids = $batch->pluck('id')->all();
            $paymentsByInvoice = DB::table('client_invoice_payments')
                ->whereIn('client_invoice_id', $ids)
                ->get(['client_invoice_id', 'workspace_id', 'status', 'amount', 'refunded_amount', 'currency'])
                ->groupBy('client_invoice_id');
            $creditsByInvoice = DB::table('client_invoice_lines')
                ->whereIn('client_invoice_id', $ids)
                ->where('type', 'credit')
                ->get(['client_invoice_id', 'workspace_id', 'total_amount'])
                ->groupBy('client_invoice_id');

            foreach ($batch as $invoice) {
                $this->fold(
                    $invoice,
                    $paymentsByInvoice->get($invoice->id, collect()),
                    $creditsByInvoice->get($invoice->id, collect()),
                    $companyWorkspace,
                    $partition,
                );
            }
        }

        $result = array_values(array_filter($partitions, fn (CreditPoolPartition $pool): bool => $pool->hasActivity()));
        usort($result, fn (CreditPoolPartition $a, CreditPoolPartition $b): int => [$a->workspaceId, $a->companyId, $a->currency] <=> [$b->workspaceId, $b->companyId, $b->currency]);

        return $result;
    }

    /**
     * One invoice's funding and consumption, folded into its pool.
     *
     * @param  Collection<int, \stdClass>  $payments
     * @param  Collection<int, \stdClass>  $credits
     * @param  array<int, int>  $companyWorkspace
     * @param  callable(int, ?int, string): CreditPoolPartition  $partition
     */
    private function fold(\stdClass $invoice, Collection $payments, Collection $credits, array $companyWorkspace, callable $partition): void
    {
        $live = InvoiceStatus::live();
        $charged = InvoiceStatus::charged();
        $workspaceId = self::key($invoice->workspace_id);
        $companyId = self::whole($invoice->client_company_id);
        $currency = (string) $invoice->currency;
        $status = (string) $invoice->status;
        if ($payments->isEmpty() && $credits->isEmpty()) {
            return;
        }

        $pool = $partition($workspaceId, $companyId, $currency);
        if ($companyId === null || ($companyWorkspace[$companyId] ?? null) !== $workspaceId) {
            $pool->flag('invoice_company_not_in_workspace');
        }
        if (InvoiceStatus::tryFrom($status) === null) {
            $pool->flag('unreadable_invoice_status');

            return;
        }
        $total = self::whole($invoice->total_amount);
        if ($total === null) {
            $pool->flag('unreadable_amount');

            return;
        }

        $settled = 0;
        foreach ($payments as $payment) {
            if (self::whole($payment->workspace_id) !== $workspaceId) {
                $pool->flag('payment_in_another_workspace');

                continue;
            }
            $paymentStatus = InvoicePaymentStatus::tryFrom((string) $payment->status);
            if ($paymentStatus === null) {
                $pool->flag('unreadable_payment_status');

                continue;
            }
            if ($paymentStatus !== InvoicePaymentStatus::Succeeded) {
                continue;
            }
            if ((string) $payment->currency !== $currency) {
                $pool->flag('payment_currency_differs_from_invoice');

                continue;
            }
            $amount = self::whole($payment->amount);
            $refunded = self::whole($payment->refunded_amount);
            if ($amount === null || $refunded === null) {
                $pool->flag('unreadable_amount');

                continue;
            }
            $settled += $amount - $refunded;
        }
        if (in_array($status, $live, true)) {
            $pool->fund(max(0, $settled - $total));
        }

        foreach ($credits as $credit) {
            if (self::whole($credit->workspace_id) !== $workspaceId) {
                $pool->flag('credit_line_in_another_workspace');

                continue;
            }
            $creditAmount = self::whole($credit->total_amount);
            if ($creditAmount === null) {
                $pool->flag('unreadable_amount');

                continue;
            }
            if ($creditAmount > 0) {
                $pool->flag('credit_line_with_positive_amount');

                continue;
            }
            if (in_array($status, $charged, true)) {
                $pool->consume(-$creditAmount);
            }
        }
    }

    /**
     * A primary or not-null foreign key. The schema guarantees one, so anything
     * else is a broken database rather than a finding.
     */
    private static function key(mixed $value): int
    {
        $key = self::whole($value);
        if ($key === null) {
            throw new RuntimeException('A key column the schema requires could not be read.');
        }

        return $key;
    }

    /**
     * An integer column as the driver returned it, or null when it is not one.
     *
     * Never zero for something unreadable: a figure the audit cannot read is a
     * reason the pool is unevaluable, not an amount.
     */
    private static function whole(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : null;
    }
}
