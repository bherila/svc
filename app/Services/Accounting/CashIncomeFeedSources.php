<?php

namespace App\Services\Accounting;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\ClientInvoicePaymentRefund;
use App\Models\ProcessorMovement;
use App\Models\Workspace;
use App\Support\Accounting\AccountingFeedQuery;
use App\Support\Accounting\CashEntries;
use App\Support\Accounting\CashEntryType;
use App\Support\Accounting\FeedBounds;
use App\Support\Accounting\FeedEntry;
use App\Support\Accounting\FeedPosition;
use App\Support\Accounting\LocalDays;
use App\Support\Accounting\PaymentRef;
use App\Support\Accounting\SourceCollector;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\PaymentRefundKind;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * The three sources of the cash-income feed, each a tenant-scoped read bounded
 * by its page and loaded in a fixed number of queries per batch of rows:
 *
 * - the payments that were received (succeeded, refunded or disputed; never
 *   one still pending, failed or canceled), by their last change;
 * - the refunds recorded by hand and their reversals, which are immutable
 *   rows, at the instant each was recorded;
 * - the signed balance entries the processor reported - refunds and their
 *   reversals, disputes and fees - which `finance.processor_movements.list`
 *   serves in full.
 *
 * A processor payment's refund the processor has not reported as a movement
 * has no date to place it by; the payment entry's `refunded_amount_minor`
 * still carries the total, so the gap is visible and never guessed.
 */
final class CashIncomeFeedSources
{
    private const int PAYMENTS = 1;

    private const int RECORDED_REFUNDS = 2;

    private const int MOVEMENTS = 3;

    /**
     * @return list<Closure(FeedBounds, ?FeedPosition, int): list<FeedEntry>>
     */
    public function sources(Workspace $workspace, AccountingFeedQuery $query, ?int $companyId): array
    {
        $sources = [];
        if ($query->wants(CashEntryType::Payment->value)) {
            $sources[] = fn (FeedBounds $bounds, ?FeedPosition $after, int $need): array => $this->payments($workspace, $query, $companyId, $bounds, $after, $need);
        }
        if ($query->wants(CashEntryType::Refund->value) || $query->wants(CashEntryType::RefundReversal->value)) {
            $sources[] = fn (FeedBounds $bounds, ?FeedPosition $after, int $need): array => $this->recordedRefunds($workspace, $query, $companyId, $bounds, $after, $need);
        }
        if ($query->entryType !== CashEntryType::Payment->value) {
            $sources[] = fn (FeedBounds $bounds, ?FeedPosition $after, int $need): array => $this->movements($workspace, $query, $companyId, $bounds, $after, $need);
        }

        return $sources;
    }

    /** @return list<FeedEntry> */
    private function payments(Workspace $workspace, AccountingFeedQuery $query, ?int $companyId, FeedBounds $bounds, ?FeedPosition $after, int $need): array
    {
        $from = $query->from;
        $to = $query->to;

        return SourceCollector::collect(
            fetch: fn (?array $below, int $batch) => FeedSql::apply(
                $this->invoiceScope(ClientInvoicePayment::query()
                    ->join('client_invoices', 'client_invoices.id', '=', 'client_invoice_payments.client_invoice_id')
                    ->where('client_invoice_payments.workspace_id', $workspace->id)
                    ->whereIn('client_invoice_payments.status', CashEntries::receivedStatuses())
                    ->select('client_invoice_payments.*')
                    ->selectRaw('client_invoice_payments.updated_at as feed_at'), $workspace, $query, $companyId)
                    ->when($from !== null, static fn (Builder $payments) => $payments->where('client_invoice_payments.received_on', '>=', $from))
                    ->when($to !== null, static fn (Builder $payments) => $payments->where('client_invoice_payments.received_on', '<=', $to)),
                'client_invoice_payments.updated_at', 'client_invoice_payments.id', $bounds, $after, $below, self::PAYMENTS, $batch, false,
            )->get()->values()->all(),
            key: static fn (ClientInvoicePayment $payment): array => [(string) $payment->getAttribute('feed_at'), (int) $payment->id],
            expand: fn (array $payments): array => $this->paymentEntries($workspace, $payments),
            after: $after,
            need: $need,
        );
    }

    /**
     * @param  array<int, ClientInvoicePayment>  $payments
     * @return list<FeedEntry>
     */
    private function paymentEntries(Workspace $workspace, array $payments): array
    {
        $refs = $this->refs($workspace, $payments);
        $entries = [];
        foreach ($payments as $payment) {
            $entries[] = CashEntries::payment(
                new FeedPosition(FeedSql::instant($payment->getAttribute('feed_at')), self::PAYMENTS, (int) $payment->id),
                $refs[$payment->id],
                (string) $payment->public_id,
                AgentApiVersion::for($payment),
                (int) $payment->amount,
                (int) $payment->refunded_amount,
                $payment->provider_fee_amount === null ? null : (int) $payment->provider_fee_amount,
                $payment->received_on?->toDateString() ?? '',
                is_string($payment->getAttribute('method')) ? $payment->getAttribute('method') : null,
                is_string($payment->getAttribute('provider')) ? $payment->getAttribute('provider') : null,
                (string) $payment->status,
            );
        }

        return $entries;
    }

    /** @return list<FeedEntry> */
    private function recordedRefunds(Workspace $workspace, AccountingFeedQuery $query, ?int $companyId, FeedBounds $bounds, ?FeedPosition $after, int $need): array
    {
        [$start, $end] = LocalDays::instants($query->from, $query->to, $workspace->timezone);
        $kind = match ($query->entryType) {
            CashEntryType::Refund->value => PaymentRefundKind::Refund->value,
            CashEntryType::RefundReversal->value => PaymentRefundKind::Reversal->value,
            default => null,
        };

        return SourceCollector::collect(
            fetch: fn (?array $below, int $batch) => FeedSql::apply(
                $this->invoiceScope(ClientInvoicePaymentRefund::query()
                    ->join('client_invoice_payments', 'client_invoice_payments.id', '=', 'client_invoice_payment_refunds.client_invoice_payment_id')
                    ->join('client_invoices', 'client_invoices.id', '=', 'client_invoice_payments.client_invoice_id')
                    ->where('client_invoice_payment_refunds.workspace_id', $workspace->id)
                    ->where('client_invoice_payments.workspace_id', $workspace->id)
                    ->select('client_invoice_payment_refunds.*')
                    ->selectRaw('client_invoice_payment_refunds.created_at as feed_at'), $workspace, $query, $companyId)
                    ->when($kind !== null, static fn (Builder $refunds) => $refunds->where('client_invoice_payment_refunds.kind', $kind))
                    ->when($start !== null, static fn (Builder $refunds) => $refunds->where('client_invoice_payment_refunds.created_at', '>=', $start))
                    ->when($end !== null, static fn (Builder $refunds) => $refunds->where('client_invoice_payment_refunds.created_at', '<', $end)),
                'client_invoice_payment_refunds.created_at', 'client_invoice_payment_refunds.id', $bounds, $after, $below, self::RECORDED_REFUNDS, $batch, false,
            )->get()->values()->all(),
            key: static fn (ClientInvoicePaymentRefund $refund): array => [(string) $refund->getAttribute('feed_at'), (int) $refund->id],
            expand: fn (array $refunds): array => $this->refundEntries($workspace, $refunds),
            after: $after,
            need: $need,
        );
    }

    /**
     * @param  array<int, ClientInvoicePaymentRefund>  $refunds
     * @return list<FeedEntry>
     */
    private function refundEntries(Workspace $workspace, array $refunds): array
    {
        if ($refunds === []) {
            return [];
        }
        $refs = $this->refs($workspace, $this->paymentsOf($workspace, $refunds));
        $reversed = ClientInvoicePaymentRefund::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', array_values(array_filter(array_map(static fn (ClientInvoicePaymentRefund $refund): ?int => $refund->reverses_refund_id, $refunds))))
            ->pluck('public_id', 'id');

        $entries = [];
        foreach ($refunds as $refund) {
            $instant = FeedSql::instant($refund->getAttribute('feed_at'));
            $entries[] = CashEntries::recordedRefund(
                new FeedPosition($instant, self::RECORDED_REFUNDS, (int) $refund->id),
                $refs[$refund->client_invoice_payment_id],
                (string) $refund->public_id,
                $refund->kind,
                (int) $refund->amount,
                $refund->created_at->setTimezone($workspace->timezone)->toDateString(),
                $refund->reverses_refund_id === null ? null : (string) $reversed->get($refund->reverses_refund_id),
            );
        }

        return $entries;
    }

    /** @return list<FeedEntry> */
    private function movements(Workspace $workspace, AccountingFeedQuery $query, ?int $companyId, FeedBounds $bounds, ?FeedPosition $after, int $need): array
    {
        $from = $query->from;
        $to = $query->to;
        $kind = $query->entryType;

        return SourceCollector::collect(
            fetch: fn (?array $below, int $batch) => FeedSql::apply(
                $this->invoiceScope(ProcessorMovement::query()
                    ->join('client_invoice_payments', 'client_invoice_payments.id', '=', 'processor_movements.client_invoice_payment_id')
                    ->join('client_invoices', 'client_invoices.id', '=', 'client_invoice_payments.client_invoice_id')
                    ->where('processor_movements.workspace_id', $workspace->id)
                    ->where('client_invoice_payments.workspace_id', $workspace->id)
                    ->select('processor_movements.*')
                    ->selectRaw('processor_movements.updated_at as feed_at'), $workspace, $query, $companyId)
                    ->when($kind !== null, static fn (Builder $movements) => $movements->where('processor_movements.kind', $kind))
                    ->when($from !== null, static fn (Builder $movements) => $movements->where('processor_movements.occurred_on', '>=', $from))
                    ->when($to !== null, static fn (Builder $movements) => $movements->where('processor_movements.occurred_on', '<=', $to)),
                'processor_movements.updated_at', 'processor_movements.id', $bounds, $after, $below, self::MOVEMENTS, $batch, true,
            )->get()->values()->all(),
            key: static fn (ProcessorMovement $movement): array => [(string) $movement->getAttribute('feed_at'), (int) $movement->id],
            expand: fn (array $movements): array => $this->movementEntries($workspace, $movements),
            after: $after,
            need: $need,
        );
    }

    /**
     * @param  array<int, ProcessorMovement>  $movements
     * @return list<FeedEntry>
     */
    private function movementEntries(Workspace $workspace, array $movements): array
    {
        if ($movements === []) {
            return [];
        }
        $refs = $this->refs($workspace, $this->paymentsOf($workspace, $movements));
        $entries = [];
        foreach ($movements as $movement) {
            $entries[] = CashEntries::movement(
                new FeedPosition(FeedSql::instant($movement->getAttribute('feed_at')), self::MOVEMENTS, (int) $movement->id),
                $refs[$movement->client_invoice_payment_id],
                (string) $movement->public_id,
                $movement->kind,
                (int) $movement->principal_minor,
                (int) $movement->fee_minor,
                $movement->occurred_on->toDateString(),
                (string) $movement->provider,
                (bool) $movement->needs_review,
            );
        }

        return $entries;
    }

    /**
     * The payments of the invoices this feed may read: the workspace's,
     * narrowed to the client and the invoice the query names.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $rows  already joined to `client_invoices`
     * @return Builder<TModel>
     */
    private function invoiceScope(Builder $rows, Workspace $workspace, AccountingFeedQuery $query, ?int $companyId): Builder
    {
        return $rows
            ->where('client_invoices.workspace_id', $workspace->id)
            ->when($companyId !== null, static fn (Builder $scoped) => $scoped->where('client_invoices.client_company_id', $companyId))
            ->when($query->invoiceId !== null, static fn (Builder $scoped) => $scoped->where('client_invoices.public_id', $query->invoiceId));
    }

    /**
     * The payments a batch of refunds or movements belongs to.
     *
     * @param  array<int, ClientInvoicePaymentRefund|ProcessorMovement>  $rows
     * @return array<int, ClientInvoicePayment>
     */
    private function paymentsOf(Workspace $workspace, array $rows): array
    {
        return ClientInvoicePayment::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', array_values(array_unique(array_map(static fn (ClientInvoicePaymentRefund|ProcessorMovement $row): int => $row->client_invoice_payment_id, $rows))))
            ->get()
            ->values()
            ->all();
    }

    /**
     * Each payment's invoice and client, by public id.
     *
     * @param  array<int, ClientInvoicePayment>  $payments
     * @return array<int, PaymentRef> payment id => reference
     */
    private function refs(Workspace $workspace, array $payments): array
    {
        $invoices = ClientInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', array_values(array_unique(array_map(static fn (ClientInvoicePayment $payment): int => $payment->client_invoice_id, $payments))))
            ->get()
            ->keyBy('id');
        $clients = ClientCompany::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $invoices->pluck('client_company_id')->unique()->values()->all())
            ->pluck('public_id', 'id');

        $refs = [];
        foreach ($payments as $payment) {
            $invoice = $invoices->get($payment->client_invoice_id);
            $refs[$payment->id] = new PaymentRef(
                (string) $payment->public_id,
                (string) $invoice?->public_id,
                (string) $invoice?->invoice_number,
                (string) $clients->get($invoice?->client_company_id),
                (string) $payment->currency,
            );
        }

        return $refs;
    }
}
