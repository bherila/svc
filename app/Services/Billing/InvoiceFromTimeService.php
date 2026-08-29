<?php

namespace App\Services\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\InvoiceKind;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Creates a draft only from explicitly selected, approved time. */
final class InvoiceFromTimeService
{
    public function __construct(
        private readonly InvoiceLifecycleService $invoices,
        private readonly InvoiceNumberAllocator $numbers,
        private readonly TimeEntryProjectChainGuard $projectChainGuard,
    ) {}

    /** @param array<string, mixed> $attributes
     * @param list<string> $timeEntryIds
     * @param list<array<string, mixed>> $manualLines */
    public function create(Workspace $workspace, ClientCompany $company, array $attributes, array $timeEntryIds, array $manualLines = []): ClientInvoice
    {
        return DB::transaction(function () use ($workspace, $company, $attributes, $timeEntryIds, $manualLines): ClientInvoice {
            $attributes['currency'] = MoneyService::currency(strtoupper((string) ($attributes['currency'] ?? $workspace->default_currency)));
            $attributes['invoice_number'] ??= $this->numbers->next($workspace);
            [$lines, $subtotalOverrides] = $this->prepareLines($workspace, $company, $attributes['currency'], $timeEntryIds, $manualLines);
            $invoice = $this->invoices->createDraft($workspace, $company, $attributes, $lines, $subtotalOverrides);
            $this->attachTime($invoice, $workspace, $lines);

            return $invoice->fresh(['lines', 'clientCompany']);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $timeEntryIds
     * @param  list<array<string, mixed>>  $manualLines
     */
    public function updateDraft(ClientInvoice $invoice, Workspace $workspace, string $expectedVersion, array $attributes, array $timeEntryIds, array $manualLines): ClientInvoice
    {
        return DB::transaction(function () use ($invoice, $workspace, $expectedVersion, $attributes, $timeEntryIds, $manualLines): ClientInvoice {
            $locked = ClientInvoice::query()->whereKey($invoice->id)->where('workspace_id', $workspace->id)->lockForUpdate()->with('clientCompany')->firstOrFail();
            if ($locked->status !== 'draft') {
                throw new DomainException('Only draft invoices can be updated.');
            }
            abort_unless(AgentApiVersion::matches($locked, $expectedVersion), 409, 'The invoice has changed; read it and retry.');
            $attributes['currency'] = MoneyService::currency(strtoupper((string) ($attributes['currency'] ?? $locked->currency)));
            [$lines, $subtotalOverrides] = $this->prepareLines($workspace, $locked->clientCompany, $attributes['currency'], $timeEntryIds, $manualLines, $locked);
            $updated = $this->invoices->updateDraft($locked, $workspace, $attributes, $lines, $subtotalOverrides);
            $this->attachTime($updated, $workspace, $lines);

            return $updated->fresh(['lines', 'clientCompany']);
        });
    }

    /**
     * Recompose the selected-time portion of an ad-hoc draft after one of its
     * entries changes.
     *
     * Lines without a time pivot are operator-authored and remain byte-for-byte
     * in place. Linked lines are derived data, so they are rebuilt from the
     * surviving approved entries using the same integer-minute calculation as
     * invoice creation. The mutated entry may legitimately disappear from the
     * selection (delete, non-billable or deferred); any other selected entry
     * becoming invalid is treated as corruption and fails the whole mutation.
     */
    public function regenerateDraftSelection(
        ClientInvoice $invoice,
        Workspace $workspace,
        int $mutatedEntryId,
    ): ClientInvoice {
        return DB::transaction(function () use ($invoice, $workspace, $mutatedEntryId): ClientInvoice {
            $locked = ClientInvoice::query()
                ->whereKey($invoice->id)
                ->where('workspace_id', $workspace->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== 'draft' || $locked->invoiceKindValue() !== InvoiceKind::AdHoc->value) {
                throw new DomainException('Only an ad-hoc draft invoice can be refreshed from selected time.');
            }
            $locked->assertLineOwnership();

            $company = ClientCompany::query()
                ->whereKey($locked->client_company_id)
                ->where('workspace_id', $workspace->id)
                ->first();
            if (! $company instanceof ClientCompany) {
                throw new DomainException('The ad-hoc draft does not belong to an available client company.');
            }

            $links = DB::table('client_invoice_line_time_entries as pivot')
                ->join('client_invoice_lines as lines', 'lines.id', '=', 'pivot.client_invoice_line_id')
                ->where('pivot.workspace_id', $workspace->id)
                ->where('lines.workspace_id', $workspace->id)
                ->where('lines.client_invoice_id', $locked->id)
                ->orderBy('lines.sort_order')
                ->orderBy('lines.id')
                ->select([
                    'lines.id as line_id',
                    'lines.sort_order as line_sort_order',
                    'pivot.client_time_entry_id as entry_id',
                ])
                ->get();

            $entryIds = $links->pluck('entry_id')->map(fn ($id): int => (int) $id)->unique()->values();
            $entries = ClientTimeEntry::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('id', $entryIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $activeEntries = $entries->values();
            if ($activeEntries->isNotEmpty()) {
                $this->projectChainGuard->assertProjectChainsAgree(
                    $company,
                    ClientTimeEntry::query()
                        ->where('workspace_id', $workspace->id)
                        ->whereKey($activeEntries->modelKeys()),
                );
            }

            $eligible = [];
            foreach ($entryIds as $entryId) {
                $entry = $entries->get($entryId);
                $valid = $entry instanceof ClientTimeEntry
                    && $entry->client_company_id === $locked->client_company_id
                    && $entry->status === 'approved'
                    && $entry->is_billable
                    && ! $entry->is_deferred
                    && $entry->billing_rate_amount !== null
                    && $entry->currency === $locked->currency;

                if (! $valid) {
                    if ($entryId !== $mutatedEntryId) {
                        throw new DomainException('Another selected time entry is no longer invoiceable; refresh the draft explicitly.');
                    }

                    continue;
                }

                $eligible[] = $entry;
            }

            $linkedLineIds = $links->pluck('line_id')->map(fn ($id): int => (int) $id)->unique()->values();
            $hasForeignPivots = DB::table('client_invoice_line_time_entries')
                ->whereIn('client_invoice_line_id', $linkedLineIds)
                ->where(fn ($query) => $query
                    ->whereNull('workspace_id')
                    ->orWhere('workspace_id', '!=', $workspace->id))
                ->exists();
            if ($hasForeignPivots) {
                throw new DomainException('The ad-hoc draft contains a time allocation owned by another workspace.');
            }

            foreach (ClientInvoiceLine::query()
                ->where('workspace_id', $workspace->id)
                ->where('client_invoice_id', $locked->id)
                ->whereIn('id', $linkedLineIds)
                ->get() as $line) {
                $line->timeEntries()->detach();
                $line->delete();
            }

            foreach ($eligible as $entry) {
                $originalLink = $links->first(fn ($link): bool => (int) $link->entry_id === $entry->id);
                $line = $locked->lines()->create([
                    'workspace_id' => $workspace->id,
                    'client_project_id' => $entry->client_project_id,
                    'type' => 'time',
                    'description' => $entry->description,
                    'quantity' => MoneyService::hoursForMinutes($entry->minutes),
                    'unit_amount' => $entry->billing_rate_amount,
                    'tax_amount' => 0,
                    'total_amount' => MoneyService::hourlyAmount($entry->minutes, $entry->billing_rate_amount),
                    'sort_order' => (int) $originalLink->line_sort_order,
                ]);
                $line->timeEntries()->attach($entry->id, ['workspace_id' => $workspace->id]);
            }

            $locked->refresh()->recalculateTotals();

            return $locked->fresh(['lines', 'clientCompany']);
        });
    }

    /**
     * @param  list<string>  $timeEntryIds
     * @param  list<array<string, mixed>>  $manualLines
     * @return array{list<array<string,mixed>>,array<int,int>}
     */
    private function prepareLines(Workspace $workspace, ClientCompany $company, string $currency, array $timeEntryIds, array $manualLines, ?ClientInvoice $currentInvoice = null): array
    {
        if (count($timeEntryIds) !== count(array_unique($timeEntryIds))) {
            throw new DomainException('Selected time entries must be distinct.');
        }
        $entriesById = ClientTimeEntry::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('public_id', $timeEntryIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('public_id');
        if ($entriesById->count() !== count($timeEntryIds)) {
            throw new DomainException('One or more selected time entries were not found.');
        }
        // Validate after locking the selected entries. Their project link can
        // no longer change between this check and the invoice-line write.
        $this->projectChainGuard->assertProjectChainsAgree(
            $company,
            ClientTimeEntry::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($entriesById->modelKeys()),
        );
        $lines = $this->normalizeManualLines($workspace, $company, $manualLines);
        /** @var array<int, int> $subtotalOverrides */
        $subtotalOverrides = [];
        foreach ($timeEntryIds as $entryId) {
            $entry = $entriesById->get($entryId);
            if (! $entry instanceof ClientTimeEntry) {
                throw new DomainException('One or more selected time entries were not found.');
            }
            if ($entry->client_company_id !== $company->id || $entry->status !== 'approved' || ! $entry->is_billable || $entry->is_deferred || $entry->billing_rate_amount === null || $entry->currency !== $currency) {
                throw new DomainException('Selected time must be approved, billable, non-deferred, and currency-compatible.');
            }
            $allocated = $entry->invoiceLines();
            if ($currentInvoice !== null) {
                $allocated->where('client_invoice_lines.client_invoice_id', '!=', $currentInvoice->id);
            }
            if ($allocated->exists()) {
                throw new DomainException('Selected time has already been allocated to an invoice.');
            }
            $index = count($lines);
            $lines[] = [
                'client_project_id' => $entry->client_project_id,
                'type' => 'time',
                'description' => $entry->description,
                'quantity' => MoneyService::hoursForMinutes($entry->minutes),
                'unit_amount' => $entry->billing_rate_amount,
                'tax_amount' => 0,
                'sort_order' => $index,
                '_entry_id' => $entry->id,
            ];
            $subtotalOverrides[$index] = MoneyService::hourlyAmount($entry->minutes, $entry->billing_rate_amount);
        }
        if ($lines === []) {
            throw new DomainException('Select time or provide a manual line.');
        }

        return [$lines, $subtotalOverrides];
    }

    /**
     * @param  list<array<string, mixed>>  $manualLines
     * @return list<array<string, mixed>>
     */
    private function normalizeManualLines(Workspace $workspace, ClientCompany $company, array $manualLines): array
    {
        $lines = [];
        foreach ($manualLines as $line) {
            $project = null;
            if (is_string($line['project_id'] ?? null)) {
                $project = ClientProject::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('client_company_id', $company->id)
                    ->where('public_id', $line['project_id'])
                    ->first();
            } elseif (is_int($line['client_project_id'] ?? null)) {
                $project = ClientProject::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('client_company_id', $company->id)
                    ->whereKey($line['client_project_id'])
                    ->first();
            }
            if (($line['project_id'] ?? $line['client_project_id'] ?? null) !== null && $project === null) {
                throw new DomainException('The selected project is not available for this invoice.');
            }
            unset($line['project_id']);
            $line['client_project_id'] = $project?->id;
            $line['sort_order'] = count($lines);
            $lines[] = $line;
        }

        return $lines;
    }

    /** @param list<array<string,mixed>> $lines */
    private function attachTime(ClientInvoice $invoice, Workspace $workspace, array $lines): void
    {
        foreach ($invoice->lines as $index => $line) {
            if (isset($lines[$index]['_entry_id'])) {
                $line->timeEntries()->attach($lines[$index]['_entry_id'], ['workspace_id' => $workspace->id]);
            }
        }
    }
}
