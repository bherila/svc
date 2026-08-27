<?php

namespace App\Services\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientInvoicePayment;
use App\Models\ClientTimeEntry;
use App\Models\Workspace;
use App\Services\WorkspaceAuthorization;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceLineType;
use App\Support\Billing\InvoiceStatus;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class InvoiceLifecycleService
{
    public function __construct(
        private readonly WorkspaceAuthorization $workspaceAuthorization,
        private readonly OverpaymentCreditService $overpaymentCreditService = new OverpaymentCreditService,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $lines
     * @param  array<int, int>  $subtotalOverrides
     */
    public function createDraft(Workspace $workspace, ClientCompany $company, array $attributes, array $lines, array $subtotalOverrides = []): ClientInvoice
    {
        $this->assertCompanyTenant($workspace, $company);
        $currency = MoneyService::currency($attributes['currency'] ?? null);
        $totals = MoneyService::invoiceTotals($lines, $subtotalOverrides);

        return DB::transaction(function () use ($workspace, $company, $attributes, $lines, $subtotalOverrides, $currency, $totals): ClientInvoice {
            $invoice = ClientInvoice::query()->create([
                'workspace_id' => $workspace->id,
                'client_company_id' => $company->id,
                'client_agreement_id' => $attributes['client_agreement_id'] ?? null,
                'client_billing_schedule_id' => $attributes['client_billing_schedule_id'] ?? null,
                'invoice_number' => $this->requiredString($attributes['invoice_number'] ?? null, 'invoice_number'),
                'status' => 'draft',
                // Recorded rather than inferred. A null kind reads as a cadence
                // invoice, which makes the replay try to reproduce something an
                // operator typed and lets it block cadence generation through
                // the overlap guard that deliberately exempts ad-hoc work.
                'invoice_kind' => $attributes['invoice_kind'] ?? InvoiceKind::AdHoc->value,
                'issue_date' => $attributes['issue_date'] ?? null,
                'due_date' => $attributes['due_date'] ?? null,
                'service_period_start' => $attributes['service_period_start'] ?? null,
                'service_period_end' => $attributes['service_period_end'] ?? null,
                'currency' => $currency,
                ...$totals,
                'balance_amount' => $totals['total_amount'],
                'notes' => $attributes['notes'] ?? null,
                'is_visible_to_client' => (bool) ($attributes['is_visible_to_client'] ?? false),
            ]);

            $this->createLines($invoice, $workspace, $lines, $subtotalOverrides);

            return $invoice->load('lines', 'clientCompany');
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $lines
     * @param  array<int, int>  $subtotalOverrides
     */
    public function updateDraft(ClientInvoice $invoice, Workspace $workspace, array $attributes, array $lines, array $subtotalOverrides = []): ClientInvoice
    {
        return DB::transaction(function () use ($invoice, $workspace, $attributes, $lines, $subtotalOverrides): ClientInvoice {
            $locked = $this->lockInvoice($invoice, $workspace);
            if ($locked->status !== 'draft') {
                throw new DomainException('Only draft invoices can be updated.');
            }
            $currency = MoneyService::currency($attributes['currency'] ?? $locked->currency);
            $totals = MoneyService::invoiceTotals($lines, $subtotalOverrides);
            $updates = [
                'currency' => $currency,
                ...$totals,
                'balance_amount' => $totals['total_amount'],
            ];
            foreach (['due_date', 'notes'] as $attribute) {
                if (array_key_exists($attribute, $attributes)) {
                    $updates[$attribute] = $attributes[$attribute];
                }
            }

            $locked->lines()->delete();
            $locked->forceFill($updates)->save();
            $this->createLines($locked, $workspace, $lines, $subtotalOverrides);

            return $locked->fresh(['lines', 'clientCompany']);
        });
    }

    public function discardDraft(ClientInvoice $invoice, Workspace $workspace, string $reason): ClientInvoice
    {
        return DB::transaction(function () use ($invoice, $workspace, $reason): ClientInvoice {
            $locked = $this->lockInvoice($invoice, $workspace);
            if ($locked->status !== 'draft') {
                throw new DomainException('Only a draft invoice can be discarded.');
            }

            $this->releaseAllocations($locked);
            $locked->forceFill([
                'status' => 'void',
                'voided_at' => now(),
                'void_reason' => $reason,
                'balance_amount' => 0,
            ])->save();

            return $locked->fresh(['lines', 'clientCompany']);
        });
    }

    public function issue(ClientInvoice $invoice, ?Workspace $workspace = null): ClientInvoice
    {
        return DB::transaction(function () use ($invoice, $workspace): ClientInvoice {
            $locked = $this->lockInvoice($invoice, $workspace);

            if ($locked->status !== 'draft') {
                if (InvoiceStatus::fromStored($locked->status)->hasCharged()) {
                    return $locked;
                }

                throw new DomainException('Only draft invoices can be issued.');
            }

            $issueDate = $locked->issue_date ?? CarbonImmutable::today();
            if ($locked->due_date !== null && $locked->due_date->lt($issueDate)) {
                throw new DomainException('The due date cannot precede the issue date.');
            }

            // Credit is only spent at issue. Two drafts can each be offered the
            // whole available pool - that is deliberate, since drafts regenerate
            // freely and reserving against them would strand credit - so the
            // pool is re-checked here, where the money actually leaves it.
            //
            // The invoice row lock is not enough: two different drafts lock two
            // different rows, so both could read the same unconsumed pool. The
            // company is what the pool belongs to, so that is what serializes.
            ClientCompany::query()->whereKey($locked->client_company_id)->lockForUpdate()->first();
            $this->capOverpaymentCreditAtIssue($locked);

            $locked->forceFill([
                'issue_date' => $issueDate,
                'due_date' => $locked->due_date ?? $issueDate,
                'issued_at' => now(),
                'status' => 'issued',
                'is_visible_to_client' => true,
                'balance_amount' => $locked->total_amount,
            ])->save();

            foreach ($locked->lines()->with('timeEntries')->get() as $line) {
                $line->timeEntries()->where('status', 'approved')->update([
                    'status' => 'invoiced',
                    'lock_version' => DB::raw('lock_version + 1'),
                ]);
            }

            return $locked->fresh(['lines', 'clientCompany']);
        });
    }

    /**
     * Trim this invoice's credit line to whatever the pool can still cover.
     *
     * Without this, two drafts prepared against the same overpayment can both
     * be issued and both consume it, handing the client the credit twice. The
     * check belongs at issue rather than in the draft calculation because issue
     * is the first moment the spend becomes real, and it is serialized by the
     * row lock taken above.
     */
    private function capOverpaymentCreditAtIssue(ClientInvoice $invoice): void
    {
        $creditLine = $invoice->lines()
            ->where('type', InvoiceLineType::Credit->value)
            ->first();

        if (! $creditLine instanceof ClientInvoiceLine) {
            return;
        }

        $company = $invoice->clientCompany;
        if (! $company instanceof ClientCompany) {
            return;
        }

        $applied = abs((int) $creditLine->total_amount);
        $available = (int) round($this->overpaymentCreditService
            ->availableCreditForCompany($company, (string) $invoice->currency) * 100);

        if ($applied <= $available) {
            return;
        }

        if ($available <= 0) {
            $creditLine->delete();
        } else {
            $creditLine->forceFill([
                'unit_amount' => -$available,
                'total_amount' => -$available,
            ])->save();
        }

        $invoice->refresh();
        $invoice->recalculateTotals();
    }

    public function void(ClientInvoice $invoice, ?Workspace $workspace = null, ?string $reason = null): ClientInvoice
    {
        return DB::transaction(function () use ($invoice, $workspace, $reason): ClientInvoice {
            $locked = $this->lockInvoice($invoice, $workspace);

            if ($locked->status === 'void') {
                return $locked;
            }

            if ($locked->paid_amount > 0 || $locked->status === 'paid') {
                throw new DomainException('A paid invoice cannot be voided.');
            }

            $hasPendingPayments = $locked->payments()->where('status', 'pending')->exists();
            if ($hasPendingPayments) {
                throw new DomainException('Cancel or resolve pending payments before voiding this invoice.');
            }

            $this->releaseAllocations($locked);
            $locked->forceFill(['status' => 'void', 'voided_at' => now(), 'void_reason' => $reason, 'balance_amount' => 0])->save();

            return $locked->fresh(['lines', 'clientCompany']);
        });
    }

    /** @param array<string, mixed> $data */
    public function applyPayment(ClientInvoice $invoice, array $data, ?Workspace $workspace = null): ClientInvoicePayment
    {
        return DB::transaction(function () use ($invoice, $data, $workspace): ClientInvoicePayment {
            $locked = $this->lockInvoice($invoice, $workspace);
            if ($locked->status === 'void') {
                throw new DomainException('A payment cannot be applied to a void invoice.');
            }
            if ($locked->status === 'draft') {
                throw new DomainException('A draft invoice must be issued before accepting payment.');
            }

            $currency = MoneyService::currency($data['currency'] ?? null);
            if ($currency !== $locked->currency) {
                throw new DomainException('Payment currency must match the invoice currency.');
            }

            $amount = MoneyService::nonNegativeInteger($data['amount'] ?? null, 'amount');
            if ($amount === 0) {
                throw new DomainException('Payment amount must be greater than zero.');
            }
            if (($data['status'] ?? 'succeeded') === 'succeeded' && $amount > $locked->balance_amount) {
                throw new DomainException('Payment cannot exceed the invoice balance.');
            }

            $key = isset($data['idempotency_key']) ? (string) $data['idempotency_key'] : null;
            if ($key !== null && $key !== '') {
                $existing = ClientInvoicePayment::query()
                    ->where('workspace_id', $locked->workspace_id)
                    ->where('idempotency_key', $key)
                    ->first();
                if ($existing !== null) {
                    if ($existing->client_invoice_id !== $locked->id
                        || $existing->amount !== $amount
                        || $existing->currency !== $currency
                        || $existing->method !== ($data['method'] ?? null)
                        || $existing->status !== ($data['status'] ?? 'succeeded')) {
                        throw new DomainException('The idempotency key is already bound to a different payment.');
                    }

                    return $existing;
                }
            }

            $payment = $locked->payments()->create([
                'workspace_id' => $locked->workspace_id,
                'status' => $data['status'] ?? 'succeeded',
                'amount' => $amount,
                'refunded_amount' => 0,
                'currency' => $currency,
                'received_on' => $data['received_on'] ?? now()->toDateString(),
                'method' => $this->requiredString($data['method'] ?? null, 'method'),
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'provider' => $data['provider'] ?? null,
                'provider_payment_identifier' => $data['provider_payment_identifier'] ?? null,
                'external_finance_transaction_uuid' => $data['external_finance_transaction_uuid'] ?? null,
                'idempotency_key' => $key,
            ]);

            $this->refreshStatus($locked);

            return $payment->fresh();
        });
    }

    public function setPaymentStatus(ClientInvoicePayment $payment, string $status, ?Workspace $workspace = null): ClientInvoicePayment
    {
        if (! in_array($status, ['pending', 'succeeded', 'failed', 'refunded', 'disputed'], true)) {
            throw new DomainException('Unsupported payment status.');
        }

        return DB::transaction(function () use ($payment, $status, $workspace): ClientInvoicePayment {
            $query = ClientInvoicePayment::query()->whereKey($payment->id)->lockForUpdate();
            if ($workspace !== null) {
                $query->where('workspace_id', $workspace->id);
            }
            $lockedPayment = $query->firstOrFail();
            $invoice = $this->lockInvoice($lockedPayment->invoice, $workspace);
            if ($status === 'succeeded' && $invoice->status === 'void') {
                throw new DomainException('A payment succeeded against a void invoice; refund it or un-void the invoice before recording it.');
            }
            if ($status === 'succeeded') {
                $otherPaid = (int) $invoice->payments()
                    ->where('id', '!=', $lockedPayment->id)
                    ->where('status', 'succeeded')
                    ->get(['amount', 'refunded_amount'])
                    ->sum(fn (ClientInvoicePayment $other): int => max(0, $other->amount - $other->refunded_amount));
                if (($lockedPayment->amount - $lockedPayment->refunded_amount) > ($invoice->total_amount - $otherPaid)) {
                    throw new DomainException('Successful payments cannot exceed the invoice total.');
                }
            }
            if ($status === 'refunded') {
                $this->assertReconciliationCapacity($lockedPayment, $lockedPayment->amount);
            }
            $lockedPayment->forceFill([
                'status' => $status,
                'refunded_amount' => $status === 'refunded'
                    ? $lockedPayment->amount
                    : $lockedPayment->refunded_amount,
            ])->save();
            $this->refreshStatus($invoice);

            return $lockedPayment->fresh();
        });
    }

    public function setRefundedAmount(ClientInvoicePayment $payment, int $amount, ?Workspace $workspace = null): ClientInvoicePayment
    {
        return DB::transaction(function () use ($payment, $amount, $workspace): ClientInvoicePayment {
            $query = ClientInvoicePayment::query()->whereKey($payment->id)->lockForUpdate();
            if ($workspace !== null) {
                $query->where('workspace_id', $workspace->id);
            }

            $lockedPayment = $query->firstOrFail();
            if (! in_array($lockedPayment->status, ['succeeded', 'refunded'], true)) {
                throw new DomainException('Only a successful payment can be refunded.');
            }
            if ($amount < 0 || $amount > $lockedPayment->amount) {
                throw new DomainException('Refunded amount must be between zero and the payment amount.');
            }
            $this->assertReconciliationCapacity($lockedPayment, $amount);

            $invoice = $this->lockInvoice($lockedPayment->invoice, $workspace);
            $lockedPayment->forceFill([
                'refunded_amount' => $amount,
                'status' => $amount === $lockedPayment->amount ? 'refunded' : 'succeeded',
            ])->save();
            $this->refreshStatus($invoice);

            return $lockedPayment->fresh();
        });
    }

    public function refreshStatus(ClientInvoice $invoice): ClientInvoice
    {
        $paid = (int) $invoice->payments()
            ->where('status', 'succeeded')
            ->get(['amount', 'refunded_amount'])
            ->sum(fn (ClientInvoicePayment $payment): int => max(0, $payment->amount - $payment->refunded_amount));
        $paid = min($paid, (int) $invoice->total_amount);
        $balance = max(0, (int) $invoice->total_amount - $paid);
        $status = $invoice->status === 'void'
            ? 'void'
            : ($paid >= (int) $invoice->total_amount ? 'paid' : ($paid > 0 ? 'partially_paid' : 'issued'));

        $invoice->forceFill([
            'paid_amount' => $paid,
            'balance_amount' => $balance,
            'status' => $status,
        ])->save();

        return $invoice->fresh();
    }

    public function assertTenant(Workspace $workspace, ClientInvoice $invoice): void
    {
        $this->workspaceAuthorization->assertOwnedBy($workspace, $invoice);
    }

    /** @param array<string, mixed> $line */
    public static function lineTotal(array $line, ?int $subtotalOverride = null): int
    {
        $totals = MoneyService::invoiceTotals([$line], $subtotalOverride === null ? [] : [0 => $subtotalOverride]);

        return $totals['subtotal_amount'] + $totals['tax_amount'];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<int, int>  $subtotalOverrides
     */
    private function createLines(ClientInvoice $invoice, Workspace $workspace, array $lines, array $subtotalOverrides): void
    {
        foreach ($lines as $index => $line) {
            $lineTotal = self::lineTotal($line, $subtotalOverrides[$index] ?? null);
            $invoice->lines()->create([
                'workspace_id' => $workspace->id,
                'client_project_id' => $line['client_project_id'] ?? null,
                'type' => $this->requiredString($line['type'] ?? null, 'line type'),
                'description' => $this->requiredString($line['description'] ?? null, 'line description'),
                'quantity' => $line['quantity'],
                'unit_amount' => MoneyService::nonNegativeInteger($line['unit_amount'] ?? null, 'unit_amount'),
                'tax_amount' => MoneyService::nonNegativeInteger($line['tax_amount'] ?? 0, 'tax_amount'),
                'total_amount' => $lineTotal,
                'sort_order' => $this->nonNegativeSortOrder($line['sort_order'] ?? 0),
            ]);
        }
    }

    private function releaseAllocations(ClientInvoice $invoice): void
    {
        $lineIds = $invoice->lines()->pluck('id');
        if ($lineIds->isEmpty()) {
            return;
        }
        $entryIds = DB::table('client_invoice_line_time_entries')
            ->whereIn('client_invoice_line_id', $lineIds)
            ->pluck('client_time_entry_id');
        if ($entryIds->isNotEmpty()) {
            ClientTimeEntry::query()->whereIn('id', $entryIds)->lockForUpdate()->get();
            ClientTimeEntry::query()->whereIn('id', $entryIds)->where('status', 'invoiced')->update([
                'status' => 'approved',
                'lock_version' => DB::raw('lock_version + 1'),
            ]);
        }
        DB::table('client_invoice_line_time_entries')->whereIn('client_invoice_line_id', $lineIds)->delete();

        // A milestone's claim is a column on the task, not a pivot row, so it
        // survives everything above. Left set, the task stays attached to a void
        // invoice and the generator - which only picks up unclaimed tasks - omits
        // the milestone from the replacement invoice permanently.
        DB::table('client_tasks')->whereIn('client_invoice_line_id', $lineIds)->update(['client_invoice_line_id' => null]);
    }

    private function lockInvoice(ClientInvoice $invoice, ?Workspace $workspace): ClientInvoice
    {
        $query = ClientInvoice::query()->whereKey($invoice->id)->lockForUpdate();
        if ($workspace !== null) {
            $query->where('workspace_id', $workspace->id);
        }

        return $query->firstOrFail();
    }

    private function assertCompanyTenant(Workspace $workspace, ClientCompany $company): void
    {
        $this->workspaceAuthorization->assertOwnedBy($workspace, $company);
    }

    private function requiredString(mixed $value, string $name): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new DomainException("{$name} is required.");
        }

        return trim($value);
    }

    private function nonNegativeSortOrder(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new DomainException('sort_order must be a non-negative integer.');
    }

    private function assertReconciliationCapacity(ClientInvoicePayment $payment, int $refundedAmount): void
    {
        $activeAllocated = (int) $payment->reconciliations()
            ->where('is_active', true)
            ->sum('allocated_amount');
        $netAmount = max(0, $payment->amount - $refundedAmount);

        if ($activeAllocated > $netAmount) {
            throw new DomainException('Refunding this payment would exceed its active finance reconciliation allocations.');
        }
    }
}
