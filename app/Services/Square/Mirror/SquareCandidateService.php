<?php

namespace App\Services\Square\Mirror;

use App\Models\AgentPrincipal;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\InvoiceSquareLink;
use App\Models\SquareInvoiceSnapshot;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Square\SquareConfiguration;
use App\Services\Square\SquareLinkRepository;
use App\Services\Square\SquareRequestFailed;
use App\Support\Billing\InvoiceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;

/**
 * Square invoices that could be an svc invoice's counterpart, ranked with
 * reasons (#438). Reads svc's mirror only; nothing is linked here.
 *
 * The pool is the client's confirmed Square customer's invoices, and the
 * invoices of the Square series its agreement is billed in. A client with
 * neither gets no suggestions - find the Square invoice by hand instead.
 * Canceled Square invoices are left out unless asked for, a Square invoice in
 * another currency is never offered, and one already linked to a different
 * svc invoice is reported rather than offered: one Square invoice links to one
 * svc invoice, and when it covers what svc split in two the other invoice
 * records that it is covered.
 */
final class SquareCandidateService
{
    public const int DEFAULT_LIMIT = 10;

    public const int MAX_LIMIT = 25;

    /** Mirrored invoices considered at most: decades of one client's billing. */
    public const int MAX_POOL = 2000;

    public function __construct(
        private readonly AgentAccess $access,
        private readonly SquareLinkRepository $links,
        private readonly SquareMirrorReadService $mirror,
        private readonly SquareMirrorPresenter $presenter,
    ) {}

    /** @return array{data: list<array<string, mixed>>, meta: array<string, mixed>} */
    public function candidates(User|AgentPrincipal $actor, Workspace $workspace, string $invoiceId, bool $includeCanceled = false, int $limit = self::DEFAULT_LIMIT): array
    {
        Validator::make(['limit' => $limit], ['limit' => ['integer', 'min:1', 'max:'.self::MAX_LIMIT]])->validate();
        abort_unless($this->access->isWorkspaceManager($actor, $workspace), 403, 'Square candidates are available to workspace managers only.');
        if (! SquareConfiguration::fromConfig()->servesWorkspace($workspace)) {
            throw SquareRequestFailed::notConnected();
        }
        $invoice = ClientInvoice::query()->where('workspace_id', $workspace->id)->where('public_id', $invoiceId)->firstOrFail();
        $company = ClientCompany::query()->where('workspace_id', $workspace->id)->whereKey($invoice->client_company_id)->firstOrFail();
        $agreement = $invoice->client_agreement_id === null ? null
            : ClientAgreement::query()->where('workspace_id', $workspace->id)->whereKey($invoice->client_agreement_id)->first();
        $customerId = $this->links->activeCustomer($company)?->square_customer_id;
        $seriesKey = $agreement?->square_series_key;
        $current = $this->links->activeLink($invoice);

        $meta = [
            'customer_id' => $customerId,
            'series_key' => $seriesKey,
            'linked_square_invoice_id' => $current?->square_invoice_id,
            'suggest_at' => SquareCandidateScorer::SUGGEST_AT,
            'ambiguous' => false,
            'reason' => null,
            'excluded' => ['canceled' => 0, 'other_currency' => 0, 'linked_elsewhere' => []],
            'pool_truncated' => false,
            ...$this->mirror->freshness($workspace),
        ];
        if ($customerId === null && $seriesKey === null) {
            $meta['reason'] = 'This client has no confirmed Square customer and its agreement is not billed in a Square series, so nothing is suggested. Confirm the client\'s Square customer, or find the Square invoice by hand.';

            return ['data' => [], 'meta' => $meta];
        }

        $pool = SquareInvoiceSnapshot::query()->where('workspace_id', $workspace->id)
            ->where(static function (Builder $query) use ($customerId, $seriesKey): void {
                if ($customerId !== null) {
                    $query->orWhere('square_customer_id', $customerId);
                }
                if ($seriesKey !== null) {
                    $query->orWhere('series_key', $seriesKey);
                }
            })
            ->orderByDesc('due_on')->orderByDesc('id')
            ->limit(self::MAX_POOL + 1)
            ->get();
        if ($pool->count() > self::MAX_POOL) {
            $pool->pop();
            $meta['pool_truncated'] = true;
        }
        $linkedTo = $this->activeLinks($workspace, array_values(array_map(static fn (SquareInvoiceSnapshot $snapshot): string => $snapshot->square_invoice_id, $pool->all())));
        $currency = strtoupper($invoice->currency);
        $offered = [];
        foreach ($pool as $snapshot) {
            if ($snapshot->status === 'CANCELED' && ! $includeCanceled) {
                $meta['excluded']['canceled']++;

                continue;
            }
            if ($snapshot->currency !== null && $snapshot->currency !== $currency) {
                $meta['excluded']['other_currency']++;

                continue;
            }
            $link = $linkedTo[$snapshot->square_invoice_id] ?? null;
            if ($link !== null && $link['client_invoice_id'] !== $invoice->id) {
                if (count($meta['excluded']['linked_elsewhere']) < 20) {
                    $meta['excluded']['linked_elsewhere'][] = [
                        'square_invoice_id' => $snapshot->square_invoice_id,
                        'invoice_number' => $snapshot->invoice_number,
                        'linked_to_invoice_id' => $link['invoice_id'],
                        'linked_to_invoice_number' => $link['invoice_number'],
                    ];
                }

                continue;
            }
            $offered[$snapshot->square_invoice_id] = $snapshot;
        }

        $cadences = [];
        $keys = array_values(array_unique(array_filter(array_map(static fn (SquareInvoiceSnapshot $snapshot): ?string => $snapshot->series_key, array_values($offered)))));
        foreach ($this->mirror->summaries($workspace, $keys) as $summary) {
            $cadences[$summary['series_key']] = $summary['cadence'];
        }
        $ranked = SquareCandidateScorer::rank($this->facts($invoice, $agreement), $customerId, array_values(array_map(static fn (SquareInvoiceSnapshot $snapshot): array => [
            'square_invoice_id' => $snapshot->square_invoice_id,
            'status' => $snapshot->status,
            'currency' => $snapshot->currency,
            'total_amount' => $snapshot->total_amount,
            'due_on' => $snapshot->due_on?->toDateString(),
            'scheduled_on' => $snapshot->scheduled_at?->toDateString(),
            'sale_or_service_date' => $snapshot->sale_or_service_date?->toDateString(),
            'square_customer_id' => $snapshot->square_customer_id,
            'series_key' => $snapshot->series_key,
            'series_cadence' => $snapshot->series_key === null ? null : ($cadences[$snapshot->series_key] ?? null),
            'invoice_number' => $snapshot->invoice_number,
        ], $offered)));
        $meta['ambiguous'] = SquareCandidateScorer::ambiguous($ranked);

        $data = [];
        foreach (array_slice($ranked, 0, $limit) as $entry) {
            $snapshot = $offered[$entry['square_invoice_id']];
            $data[] = [
                ...array_intersect_key($this->presenter->invoice($snapshot), array_flip([
                    'square_invoice_id', 'invoice_number', 'series_key', 'series_occurrence', 'location_id', 'customer_id', 'recipient_name',
                    'title', 'status', 'currency', 'total_amount', 'paid_amount', 'due_on', 'scheduled_at', 'sale_or_service_date', 'public_url',
                ])),
                'score' => $entry['score'],
                'suggested' => $entry['suggested'],
                'signals' => $entry['signals'],
                'reasons' => $entry['reasons'],
                'date_distance_days' => $entry['date_distance_days'],
                'linked_to_this_invoice' => $current !== null && $current->square_invoice_id === $snapshot->square_invoice_id,
            ];
        }

        return ['data' => $data, 'meta' => $meta];
    }

    /**
     * @return array{currency: string, total_amount: int, due_date: string|null, reference_date: string|null, cycle_start: string|null, cycle_end: string|null, service_start: string|null, service_end: string|null, cadence: string|null, settled: bool}
     */
    private function facts(ClientInvoice $invoice, ?ClientAgreement $agreement): array
    {
        $due = $invoice->due_date?->toDateString();
        $cycleStart = $invoice->cycle_start?->toDateString();
        $serviceStart = $invoice->service_period_start?->toDateString();

        return [
            'currency' => strtoupper($invoice->currency),
            'total_amount' => (int) $invoice->total_amount,
            'due_date' => $due,
            'reference_date' => $due ?? $cycleStart ?? $serviceStart ?? $invoice->issue_date?->toDateString(),
            'cycle_start' => $cycleStart,
            'cycle_end' => $invoice->cycle_end?->toDateString(),
            'service_start' => $serviceStart,
            'service_end' => $invoice->service_period_end?->toDateString(),
            'cadence' => $agreement?->billing_cadence,
            'settled' => $invoice->status === InvoiceStatus::Paid->value,
        ];
    }

    /**
     * The svc invoice each of these Square invoices is actively linked to.
     *
     * @param  list<string>  $squareInvoiceIds
     * @return array<string, array{client_invoice_id: int, invoice_id: string|null, invoice_number: string|null}>
     */
    private function activeLinks(Workspace $workspace, array $squareInvoiceIds): array
    {
        if ($squareInvoiceIds === []) {
            return [];
        }
        $links = InvoiceSquareLink::query()->where('workspace_id', $workspace->id)->whereIn('active_square_invoice_slot', $squareInvoiceIds)->get(['square_invoice_id', 'client_invoice_id']);
        $invoices = ClientInvoice::query()->where('workspace_id', $workspace->id)->whereKey($links->pluck('client_invoice_id')->all())->get(['id', 'public_id', 'invoice_number'])->keyBy('id');
        $linked = [];
        foreach ($links as $link) {
            $invoice = $invoices->get($link->client_invoice_id);
            $linked[$link->square_invoice_id] = [
                'client_invoice_id' => $link->client_invoice_id,
                'invoice_id' => $invoice?->public_id,
                'invoice_number' => $invoice?->invoice_number,
            ];
        }

        return $linked;
    }
}
