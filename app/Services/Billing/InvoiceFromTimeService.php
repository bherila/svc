<?php

namespace App\Services\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Creates a draft only from explicitly selected, approved time. */
final class InvoiceFromTimeService
{
    public function __construct(
        private readonly InvoiceLifecycleService $invoices,
        private readonly InvoiceNumberAllocator $numbers,
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
