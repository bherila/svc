<?php

namespace Tests\Concerns;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Billing\InvoiceHoursStatement;

/**
 * Synthetic invoices for the golden document tests.
 *
 * Invoices are written row by row with their stored snapshots set directly:
 * the document is a pure reading of what is stored at issue, so a fixture
 * names exactly the figures it expects to see printed. Names are obviously
 * invented and every address uses a reserved domain.
 */
trait BuildsGoldenInvoices
{
    protected Workspace $workspace;

    protected ClientCompany $company;

    protected ClientProject $project;

    protected User $member;

    protected function setUpGoldenTenant(): void
    {
        $this->member = User::factory()->create(['email' => 'golden-member@example.test']);
        $this->workspace = Workspace::query()->create(['name' => 'Synthetic golden workspace', 'slug' => 'synthetic-golden']);
        $this->workspace->memberships()->create(['user_id' => $this->member->id, 'role' => 'owner']);
        $this->company = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic Golden Client', 'slug' => 'synthetic-golden-client']);
        $this->project = $this->goldenProject('Synthetic Golden Project');
    }

    protected function goldenProject(string $name): ClientProject
    {
        return ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'name' => $name, 'is_visible_to_client' => true,
        ]);
    }

    /**
     * An issued invoice for August 2026 with one retainer line, unless told otherwise.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function goldenInvoice(array $attributes = []): ClientInvoice
    {
        // Not mass-assignable: the stored snapshots and the agreement link.
        $stored = array_intersect_key($attributes, array_flip(['hours_statement', 'deferred_work_statement', 'client_agreement_id']));
        $attributes = array_diff_key($attributes, $stored);

        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'invoice_number' => 'INV-SYN-GOLDEN-'.str()->upper(str()->random(6)),
            'status' => 'issued',
            'currency' => 'USD',
            'issue_date' => '2026-08-15',
            'due_date' => '2026-09-14',
            'service_period_start' => '2026-08-01',
            'service_period_end' => '2026-08-31',
            'subtotal_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0, 'paid_amount' => 0, 'balance_amount' => 0,
            'is_visible_to_client' => true,
            ...$attributes,
        ]);

        if ($stored !== []) {
            $invoice->forceFill($stored)->save();
        }

        return $invoice->fresh() ?? $invoice;
    }

    /** @param array<string, mixed> $attributes */
    protected function goldenLine(ClientInvoice $invoice, array $attributes = []): ClientInvoiceLine
    {
        return ClientInvoiceLine::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_invoice_id' => $invoice->id,
            'type' => 'retainer',
            'description' => 'Synthetic retainer',
            'quantity' => '1',
            'unit_amount' => 150000,
            'tax_amount' => 0,
            'total_amount' => 150000,
            'sort_order' => 0,
            ...$attributes,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    protected function goldenEntry(string $workedOn, int $minutes, array $attributes = []): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id, 'user_id' => $this->member->id,
            'worked_on' => $workedOn, 'minutes' => $minutes, 'description' => 'Synthetic internal note',
            'is_billable' => true, 'is_deferred' => false, 'status' => 'approved',
            'billing_rate_amount' => 15000, 'currency' => 'USD',
            'is_visible_to_client' => false, 'client_visible_description' => null,
            ...$attributes,
        ]);
    }

    /** Bill an entry on a line, the way the generator attaches it. */
    protected function goldenAttach(ClientInvoiceLine $line, ClientTimeEntry $entry): void
    {
        $line->timeEntries()->attach($entry->id, ['workspace_id' => $this->workspace->id]);
    }

    /**
     * A statement with every figure zero and August 2026 billed, September 2026
     * next, overridden by name.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function goldenStatement(array $overrides = []): InvoiceHoursStatement
    {
        return new InvoiceHoursStatement(...[
            'cadence' => 'monthly', 'workStart' => '2026-08-01', 'workEnd' => '2026-08-31',
            'retainerStart' => '2026-09-01', 'retainerEnd' => '2026-09-30',
            'openingRetainerHours' => 0.0, 'openingRolloverHours' => 0.0, 'openingDeficitHours' => 0.0,
            'openingExpiredHours' => 0.0, 'expiredWithinPeriodHours' => 0.0,
            'ordinaryHours' => 0.0, 'ordinaryAppliedToWorkPool' => 0.0, 'ordinaryAppliedToNextRetainer' => 0.0,
            'ordinaryBilledAtRate' => 0.0, 'subcontractorHours' => 0.0,
            'deferredAppliedHours' => 0.0, 'deferredAppliedEntries' => 0, 'recarriedSettledHours' => 0.0,
            'deferredBilledOnTerminationHours' => 0.0, 'catchUpBilledHours' => 0.0,
            'minimumAvailabilityHours' => 0.0, 'minimumAvailabilityThresholdHours' => 0.0,
            'interimBilledHours' => 0.0, 'rolledForwardHours' => 0.0, 'expiringHours' => 0.0,
            'deficitCarriedForwardHours' => 0.0, 'deferredBacklogHours' => 0.0, 'deferredBacklogEntries' => 0,
            'recarriedRemainingHours' => 0.0, 'nextRetainerHours' => 0.0,
            'retainerSoldBy' => null, 'poolRemainingHours' => null, 'availableBeforeHours' => null,
            ...$overrides,
        ]);
    }
}
