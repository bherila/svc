<?php

namespace App\Services\Accounting;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceCorrection;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\Workspace;
use App\Support\Accounting\AccountingFeedQuery;
use App\Support\Accounting\FeedBounds;
use App\Support\Accounting\FeedEntry;
use App\Support\Accounting\FeedPosition;
use App\Support\Accounting\InvoiceFacts;
use App\Support\Accounting\InvoiceLineEntries;
use App\Support\Accounting\LineEntryType;
use App\Support\Accounting\LineFacts;
use App\Support\Accounting\LocalDays;
use App\Support\Accounting\SourceCollector;
use App\Support\Billing\InvoiceStatus;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The three sources of the invoice-line feed, each a tenant-scoped read that
 * is bounded by its page and loads what it needs in a fixed number of
 * queries per batch of rows:
 *
 * - the lines of every invoice that has been issued, as they were issued
 *   (a correction's change rolled back), at the invoice's issue instant;
 * - the money changes of the audited in-place corrections, at the instant
 *   each was recorded;
 * - the lines of every issued invoice that was voided, negated, at the
 *   instant of the void.
 *
 * An invoice never issued (a draft, or a draft reopened for reissue) is no
 * entry of the books and appears in none of them.
 */
final class InvoiceLineFeedSources
{
    private const string ISSUED_AT = 'COALESCE(client_invoices.issued_at, client_invoices.created_at)';

    /**
     * When the void was recorded. `voided_at` is stored as the workspace's wall
     * clock, not UTC like every other instant here, so the row's own update
     * stamp (set by the void, and not touched after it) dates the entry.
     */
    private const string VOIDED_AT = 'client_invoices.updated_at';

    /**
     * @return list<Closure(FeedBounds, ?FeedPosition, int): list<FeedEntry>>
     */
    public function sources(Workspace $workspace, AccountingFeedQuery $query, ?int $companyId): array
    {
        $sources = [];
        if ($query->wants(LineEntryType::InvoiceLine->value)) {
            $sources[] = fn (FeedBounds $bounds, ?FeedPosition $after, int $need): array => $this->issued($workspace, $query, $companyId, $bounds, $after, $need);
        }
        if ($query->wants(LineEntryType::Correction->value)) {
            $sources[] = fn (FeedBounds $bounds, ?FeedPosition $after, int $need): array => $this->corrections($workspace, $query, $companyId, $bounds, $after, $need);
        }
        if ($query->wants(LineEntryType::Void->value)) {
            $sources[] = fn (FeedBounds $bounds, ?FeedPosition $after, int $need): array => $this->voids($workspace, $query, $companyId, $bounds, $after, $need);
        }

        return $sources;
    }

    /** @return list<FeedEntry> */
    private function issued(Workspace $workspace, AccountingFeedQuery $query, ?int $companyId, FeedBounds $bounds, ?FeedPosition $after, int $need): array
    {
        $from = $query->from;
        $to = $query->to;

        return SourceCollector::collect(
            fetch: fn (?array $below, int $batch) => FeedSql::apply(
                $this->invoiceScope(ClientInvoiceLine::query()
                    ->join('client_invoices', 'client_invoices.id', '=', 'client_invoice_lines.client_invoice_id')
                    ->where('client_invoice_lines.workspace_id', $workspace->id)
                    ->select('client_invoice_lines.*')
                    ->selectRaw(self::ISSUED_AT.' as feed_at'), $workspace, $query, $companyId)
                    ->where(fn (Builder $issued) => $this->issuedState($issued))
                    ->when($from !== null, static fn (Builder $lines) => $lines->where('client_invoices.issue_date', '>=', $from))
                    ->when($to !== null, static fn (Builder $lines) => $lines->where('client_invoices.issue_date', '<=', $to)),
                self::ISSUED_AT, 'client_invoice_lines.id', $bounds, $after, $below, LineEntryType::InvoiceLine->rank(), $batch, false,
            )->get()->values()->all(),
            key: static fn (ClientInvoiceLine $line): array => [(string) $line->getAttribute('feed_at'), (int) $line->id],
            expand: fn (array $lines): array => $this->issuedEntries($workspace, $lines),
            after: $after,
            need: $need,
        );
    }

    /**
     * @param  array<int, ClientInvoiceLine>  $lines
     * @return list<FeedEntry>
     */
    private function issuedEntries(Workspace $workspace, array $lines): array
    {
        if ($lines === []) {
            return [];
        }
        $invoices = $this->invoices($workspace, array_map(static fn (ClientInvoiceLine $line): int => $line->client_invoice_id, $lines));
        $projects = $this->projectIds($workspace, $lines);
        // Only an invoice past its first revision can have been corrected.
        $corrected = array_keys(array_filter($invoices, static fn (array $invoice): bool => $invoice['model']->document_revision > 1));
        $diffs = $this->diffsByLine($workspace, $corrected);

        $entries = [];
        foreach ($lines as $line) {
            $facts = $this->lineFacts($line, $projects);
            $position = new FeedPosition(FeedSql::instant($line->getAttribute('feed_at')), LineEntryType::InvoiceLine->rank(), (int) $line->id);
            $entries[] = InvoiceLineEntries::issued(
                $this->invoiceFacts($invoices[$line->client_invoice_id], $workspace),
                InvoiceLineEntries::asIssued($facts, $diffs[$facts->id] ?? []),
                $position,
            );
        }

        return $entries;
    }

    /** @return list<FeedEntry> */
    private function corrections(Workspace $workspace, AccountingFeedQuery $query, ?int $companyId, FeedBounds $bounds, ?FeedPosition $after, int $need): array
    {
        [$start, $end] = LocalDays::instants($query->from, $query->to, $workspace->timezone);

        return SourceCollector::collect(
            fetch: fn (?array $below, int $batch) => FeedSql::apply(
                $this->invoiceScope(ClientInvoiceCorrection::query()
                    ->join('client_invoices', 'client_invoices.id', '=', 'client_invoice_corrections.client_invoice_id')
                    ->where('client_invoice_corrections.workspace_id', $workspace->id)
                    ->select('client_invoice_corrections.*')
                    ->selectRaw('client_invoice_corrections.created_at as feed_at'), $workspace, $query, $companyId)
                    ->where(fn (Builder $issued) => $this->issuedState($issued))
                    ->when($start !== null, static fn (Builder $rows) => $rows->where('client_invoice_corrections.created_at', '>=', $start))
                    ->when($end !== null, static fn (Builder $rows) => $rows->where('client_invoice_corrections.created_at', '<', $end)),
                'client_invoice_corrections.created_at', 'client_invoice_corrections.id', $bounds, $after, $below, LineEntryType::Correction->rank(), $batch, false,
            )->get()->values()->all(),
            key: static fn (ClientInvoiceCorrection $correction): array => [(string) $correction->getAttribute('feed_at'), (int) $correction->id],
            expand: fn (array $corrections): array => $this->correctionEntries($workspace, $corrections),
            after: $after,
            need: $need,
        );
    }

    /**
     * @param  array<int, ClientInvoiceCorrection>  $corrections
     * @return list<FeedEntry>
     */
    private function correctionEntries(Workspace $workspace, array $corrections): array
    {
        if ($corrections === []) {
            return [];
        }
        $invoiceIds = array_map(static fn (ClientInvoiceCorrection $correction): int => $correction->client_invoice_id, $corrections);
        $invoices = $this->invoices($workspace, $invoiceIds);
        $lines = $this->linesByInvoice($workspace, $invoiceIds);

        $entries = [];
        foreach ($corrections as $correction) {
            $invoice = $this->invoiceFacts($invoices[$correction->client_invoice_id], $workspace);
            $position = FeedSql::instant($correction->getAttribute('feed_at'));
            foreach ($correction->line_diffs as $index => $diff) {
                $line = is_string($diff['line'] ?? null) ? ($lines[$correction->client_invoice_id][$diff['line']] ?? null) : null;
                if ($line === null) {
                    continue;
                }
                $entry = InvoiceLineEntries::corrected(
                    $invoice, $line, $diff, (string) $correction->public_id,
                    new FeedPosition($position, LineEntryType::Correction->rank(), (int) $correction->id, $index),
                );
                if ($entry !== null) {
                    $entries[] = $entry;
                }
            }
        }

        return $entries;
    }

    /** @return list<FeedEntry> */
    private function voids(Workspace $workspace, AccountingFeedQuery $query, ?int $companyId, FeedBounds $bounds, ?FeedPosition $after, int $need): array
    {
        [$start, $end] = LocalDays::instants($query->from, $query->to, $workspace->timezone);

        return SourceCollector::collect(
            fetch: fn (?array $below, int $batch) => FeedSql::apply(
                $this->invoiceScope(ClientInvoice::query()
                    ->select('client_invoices.*')
                    ->selectRaw(self::VOIDED_AT.' as feed_at'), $workspace, $query, $companyId)
                    ->where('client_invoices.status', InvoiceStatus::Void->value)
                    ->whereNotNull('client_invoices.issued_at')
                    ->when($start !== null, fn (Builder $rows) => $rows->whereRaw(self::VOIDED_AT.' >= ?', [$start]))
                    ->when($end !== null, fn (Builder $rows) => $rows->whereRaw(self::VOIDED_AT.' < ?', [$end])),
                self::VOIDED_AT, 'client_invoices.id', $bounds, $after, $below, LineEntryType::Void->rank(), $batch, false,
            )->get()->values()->all(),
            key: static fn (ClientInvoice $invoice): array => [(string) $invoice->getAttribute('feed_at'), (int) $invoice->id],
            expand: fn (array $invoices): array => $this->voidEntries($workspace, $invoices),
            after: $after,
            need: $need,
        );
    }

    /**
     * @param  array<int, ClientInvoice>  $voided
     * @return list<FeedEntry>
     */
    private function voidEntries(Workspace $workspace, array $voided): array
    {
        if ($voided === []) {
            return [];
        }
        $ids = array_map(static fn (ClientInvoice $invoice): int => $invoice->id, $voided);
        $invoices = $this->invoices($workspace, $ids);
        $lines = $this->linesByInvoice($workspace, $ids);

        $entries = [];
        foreach ($voided as $invoice) {
            $facts = $this->invoiceFacts($invoices[$invoice->id], $workspace);
            $position = FeedSql::instant($invoice->getAttribute('feed_at'));
            foreach ($lines[$invoice->id] ?? [] as $line) {
                $entries[] = InvoiceLineEntries::voided($facts, $line, new FeedPosition($position, LineEntryType::Void->rank(), (int) $invoice->id, $line->rowId));
            }
        }

        return $entries;
    }

    /**
     * The invoices this feed may read: the workspace's, narrowed to the
     * client and the invoice the query names.
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
     * An invoice that has been issued: charged, or voided after it was issued.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $rows
     */
    private function issuedState(Builder $rows): void
    {
        $rows->whereIn('client_invoices.status', InvoiceStatus::charged())
            ->orWhere(static fn (Builder $voided) => $voided
                ->where('client_invoices.status', InvoiceStatus::Void->value)
                ->whereNotNull('client_invoices.issued_at'));
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, array{model: ClientInvoice, client: string}>
     */
    private function invoices(Workspace $workspace, array $ids): array
    {
        $invoices = ClientInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', array_values(array_unique($ids)))
            ->get();
        $clients = ClientCompany::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $invoices->pluck('client_company_id')->unique()->values()->all())
            ->pluck('public_id', 'id');

        $byId = [];
        foreach ($invoices as $invoice) {
            $byId[$invoice->id] = ['model' => $invoice, 'client' => (string) $clients->get($invoice->client_company_id)];
        }

        return $byId;
    }

    /** @param  array{model: ClientInvoice, client: string}  $invoice */
    private function invoiceFacts(array $invoice, Workspace $workspace): InvoiceFacts
    {
        $model = $invoice['model'];

        return new InvoiceFacts(
            (string) $model->public_id,
            (string) $model->invoice_number,
            $model->issue_date?->toDateString(),
            $model->service_period_start?->toDateString(),
            $model->service_period_end?->toDateString(),
            (string) $model->currency,
            $invoice['client'],
            $workspace->timezone,
        );
    }

    /**
     * @param  array<int, int>  $invoiceIds
     * @return array<int, array<string, LineFacts>> invoice id => line public id => the line as it stands now
     */
    private function linesByInvoice(Workspace $workspace, array $invoiceIds): array
    {
        $lines = ClientInvoiceLine::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('client_invoice_id', array_values(array_unique($invoiceIds)))
            ->orderBy('id')
            ->get();
        $projects = $this->projectIds($workspace, $lines->values()->all());

        $byInvoice = [];
        foreach ($lines as $line) {
            $byInvoice[$line->client_invoice_id][(string) $line->public_id] = $this->lineFacts($line, $projects, (int) $line->id);
        }

        return $byInvoice;
    }

    /**
     * Every correction diff of the invoices, per line, oldest correction first.
     *
     * @param  array<int, int>  $invoiceIds
     * @return array<string, list<array<array-key, mixed>>> line public id => diffs
     */
    private function diffsByLine(Workspace $workspace, array $invoiceIds): array
    {
        if ($invoiceIds === []) {
            return [];
        }
        $byLine = [];
        $corrections = ClientInvoiceCorrection::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('client_invoice_id', $invoiceIds)
            ->orderBy('from_revision')
            ->orderBy('id')
            ->get();
        foreach ($corrections as $correction) {
            foreach ($correction->line_diffs as $diff) {
                if (is_string($diff['line'] ?? null)) {
                    $byLine[$diff['line']][] = $diff;
                }
            }
        }

        return $byLine;
    }

    /**
     * @param  array<int, ClientInvoiceLine>  $lines
     * @return Collection<int, string> project id => public id
     */
    private function projectIds(Workspace $workspace, array $lines): Collection
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (ClientInvoiceLine $line): ?int => $line->client_project_id, $lines))));
        if ($ids === []) {
            return new Collection;
        }

        return ClientProject::query()->where('workspace_id', $workspace->id)->whereIn('id', $ids)->pluck('public_id', 'id');
    }

    /** @param  Collection<int, string>  $projects */
    private function lineFacts(ClientInvoiceLine $line, Collection $projects, int $rowId = 0): LineFacts
    {
        return new LineFacts(
            (string) $line->public_id,
            (string) $line->type,
            (string) $line->description,
            (string) $line->quantity,
            (int) $line->unit_amount,
            (int) $line->tax_amount,
            (int) $line->total_amount,
            $line->client_project_id === null ? null : (string) $projects->get($line->client_project_id),
            $rowId,
        );
    }
}
