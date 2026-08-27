<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DeriveTimeEntryRatesTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::query()->create(['name' => 'Rates', 'slug' => 'rates']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Rates Client', 'slug' => 'rates-client',
        ]);
        $this->project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Rates Project',
        ]);
        $this->user = User::factory()->create();
    }

    public function test_it_resolves_the_agreement_rate_and_stamps_the_source(): void
    {
        $this->agreement(37500, '2026-01-01', null);
        $entry = $this->entry('2026-03-14');

        $this->artisan('svc:billing:derive-time-rates')->assertSuccessful();

        $entry->refresh();
        $this->assertSame(37500, $entry->billing_rate_amount);
        $this->assertSame('agreement', $entry->billing_rate_source);
    }

    public function test_it_leaves_invoiced_time_alone(): void
    {
        $this->agreement(37500, '2026-01-01', null);
        $entry = $this->entry('2026-03-14');

        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'invoice_number' => 'SVC-00001', 'currency' => 'USD', 'status' => 'issued',
        ]);
        $line = ClientInvoiceLine::query()->create([
            'workspace_id' => $this->workspace->id, 'client_invoice_id' => $invoice->id,
            'type' => 'prior_month_retainer', 'description' => 'Retainer draw', 'quantity' => '1.0000',
            // A retainer draw-down charges nothing; reading this back as a rate would be wrong.
            'unit_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0, 'sort_order' => 0,
        ]);
        $line->timeEntries()->attach($entry->id, ['workspace_id' => $this->workspace->id]);

        $this->artisan('svc:billing:derive-time-rates')->assertSuccessful();

        $this->assertNull($entry->refresh()->billing_rate_amount);
    }

    public function test_it_skips_deferred_time_unless_asked(): void
    {
        $this->agreement(37500, '2026-01-01', null);
        $entry = $this->entry('2026-03-14', deferred: true);

        $this->artisan('svc:billing:derive-time-rates')->assertSuccessful();
        $this->assertNull($entry->refresh()->billing_rate_amount);

        $this->artisan('svc:billing:derive-time-rates', ['--include-deferred' => true])->assertSuccessful();
        $this->assertSame(37500, $entry->refresh()->billing_rate_amount);
    }

    public function test_it_reports_time_no_agreement_covers(): void
    {
        $this->agreement(37500, '2026-06-01', null);
        $entry = $this->entry('2026-03-14');

        $this->artisan('svc:billing:derive-time-rates')
            ->expectsOutputToContain('no agreement in force')
            ->assertSuccessful();

        $this->assertNull($entry->refresh()->billing_rate_amount);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->agreement(37500, '2026-01-01', null);
        $entry = $this->entry('2026-03-14');

        $this->artisan('svc:billing:derive-time-rates', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull($entry->refresh()->billing_rate_amount);
    }

    public function test_it_never_replaces_a_rate_already_recorded(): void
    {
        $this->agreement(37500, '2026-01-01', null);
        $entry = $this->entry('2026-03-14');
        $entry->forceFill(['billing_rate_amount' => 12345, 'billing_rate_source' => 'explicit'])->save();

        $this->artisan('svc:billing:derive-time-rates')->assertSuccessful();

        $entry->refresh();
        $this->assertSame(12345, $entry->billing_rate_amount);
        $this->assertSame('explicit', $entry->billing_rate_source);
    }

    private function agreement(int $hourlyRate, string $startsOn, ?string $endsOn): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Hourly', 'status' => 'active', 'currency' => 'USD',
            'hourly_rate_amount' => $hourlyRate,
            'starts_on' => $startsOn, 'ends_on' => $endsOn,
        ]);
    }

    private function entry(string $workedOn, bool $deferred = false): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'worked_on' => $workedOn,
            'minutes' => 90,
            'description' => 'Imported work',
            'is_billable' => true,
            'is_deferred' => $deferred,
            'status' => 'approved',
            'currency' => 'USD',
        ]);
    }
}
