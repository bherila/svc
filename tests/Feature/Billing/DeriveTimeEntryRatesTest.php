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
use App\Support\Billing\SubcontractorBillingMode;
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

        $this->artisan('svc:billing:derive-time-rates', ['--workspace' => $this->workspace->public_id])->assertSuccessful();

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

        $this->artisan('svc:billing:derive-time-rates', ['--workspace' => $this->workspace->public_id])->assertSuccessful();

        $this->assertNull($entry->refresh()->billing_rate_amount);
    }

    public function test_it_skips_deferred_time_unless_asked(): void
    {
        $this->agreement(37500, '2026-01-01', null);
        $entry = $this->entry('2026-03-14', deferred: true);

        $this->artisan('svc:billing:derive-time-rates', ['--workspace' => $this->workspace->public_id])->assertSuccessful();
        $this->assertNull($entry->refresh()->billing_rate_amount);

        $this->artisan('svc:billing:derive-time-rates', ['--workspace' => $this->workspace->public_id, '--include-deferred' => true])->assertSuccessful();
        $this->assertSame(37500, $entry->refresh()->billing_rate_amount);
    }

    public function test_it_reports_time_no_agreement_covers(): void
    {
        $this->agreement(37500, '2026-06-01', null);
        $entry = $this->entry('2026-03-14');

        $this->artisan('svc:billing:derive-time-rates', ['--workspace' => $this->workspace->public_id])
            ->expectsOutputToContain('no agreement in force')
            ->assertSuccessful();

        $this->assertNull($entry->refresh()->billing_rate_amount);
    }

    /**
     * An agreement in force but carrying no rate prices nothing.
     *
     * The resolver refuses rather than falling through to zero. Every other
     * reader of `hourly_rate_amount` coerces a null to `0` - the overage line
     * composer among them - so a rate that reaches an invoice through those
     * paths bills the client's excess hours at no charge and reads as a
     * deliberate discount. The one place that can still tell "unpriced" from
     * "free" is this lookup, and it has to say so.
     */
    public function test_an_agreement_with_no_rate_prices_nothing(): void
    {
        $this->agreement(37500, '2026-01-01', null)
            ->forceFill(['hourly_rate_amount' => null])
            ->save();
        $entry = $this->entry('2026-03-14');

        $this->artisan('svc:billing:derive-time-rates', ['--workspace' => $this->workspace->public_id])
            ->expectsOutputToContain('no agreement in force')
            ->assertSuccessful();

        $this->assertNull($entry->refresh()->billing_rate_amount);
        $this->assertNull($entry->refresh()->billing_rate_source);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->agreement(37500, '2026-01-01', null);
        $entry = $this->entry('2026-03-14');

        $this->artisan('svc:billing:derive-time-rates', ['--workspace' => $this->workspace->public_id, '--dry-run' => true])->assertSuccessful();

        $this->assertNull($entry->refresh()->billing_rate_amount);
    }

    public function test_it_never_replaces_a_rate_already_recorded(): void
    {
        $this->agreement(37500, '2026-01-01', null);
        $entry = $this->entry('2026-03-14');
        $entry->forceFill(['billing_rate_amount' => 12345, 'billing_rate_source' => 'explicit'])->save();

        $this->artisan('svc:billing:derive-time-rates', ['--workspace' => $this->workspace->public_id])->assertSuccessful();

        $entry->refresh();
        $this->assertSame(12345, $entry->billing_rate_amount);
        $this->assertSame('explicit', $entry->billing_rate_source);
    }

    public function test_it_refuses_to_run_without_a_workspace(): void
    {
        $this->agreement(37500, '2026-01-01', null);
        $entry = $this->entry('2026-03-14');

        // Repairing one onboarding must never reach into another tenant.
        $this->artisan('svc:billing:derive-time-rates')->assertFailed();

        $this->assertNull($entry->refresh()->billing_rate_amount);
    }

    public function test_it_never_touches_another_workspace(): void
    {
        $this->agreement(37500, '2026-01-01', null);
        $mine = $this->entry('2026-03-14');

        $other = Workspace::query()->create(['name' => 'Other', 'slug' => 'other-rates']);

        $this->artisan('svc:billing:derive-time-rates', ['--workspace' => $other->public_id])->assertSuccessful();

        $this->assertNull($mine->refresh()->billing_rate_amount);
    }

    public function test_it_derives_only_consultant_and_retainer_mode_rates(): void
    {
        $this->agreement(37500, '2026-01-01', null);
        $retainer = $this->entry('2026-03-14');
        $retainer->update(['subcontractor_billing_mode' => SubcontractorBillingMode::Retainer]);
        $flat = $this->entry('2026-03-15');
        $flat->update([
            'subcontractor_billing_mode' => SubcontractorBillingMode::FlatHourly,
            'subcontractor_cost_amount' => 8000,
            'subcontractor_cost_currency' => 'EUR',
            'currency' => 'EUR',
        ]);
        $direct = $this->entry('2026-03-16');
        $direct->update([
            'subcontractor_billing_mode' => SubcontractorBillingMode::Direct,
            'currency' => 'CAD',
        ]);

        $this->artisan('svc:billing:derive-time-rates', ['--workspace' => $this->workspace->public_id])
            ->assertSuccessful();

        $this->assertSame(37500, $retainer->refresh()->billing_rate_amount);
        $this->assertNull($flat->refresh()->billing_rate_amount);
        $this->assertSame('EUR', $flat->currency);
        $this->assertNull($direct->refresh()->billing_rate_amount);
        $this->assertSame('CAD', $direct->currency);
    }

    public function test_it_resolves_an_agreement_that_has_since_ended(): void
    {
        // The importer marks every agreement with a termination date as
        // terminated, so filtering on lifecycle status would leave exactly the
        // historical entries this command exists to repair unresolvable.
        $agreement = $this->agreement(30000, '2026-01-01', '2026-03-31');
        $agreement->forceFill(['status' => 'terminated'])->save();
        $entry = $this->entry('2026-02-10');

        $this->artisan('svc:billing:derive-time-rates', ['--workspace' => $this->workspace->public_id])
            ->assertSuccessful();

        $this->assertSame(30000, $entry->refresh()->billing_rate_amount);
    }

    public function test_the_currency_written_is_the_one_the_rate_came_from(): void
    {
        $agreement = $this->agreement(37500, '2026-01-01', null);
        $agreement->forceFill(['currency' => 'EUR'])->save();
        $entry = $this->entry('2026-03-14');

        $this->artisan('svc:billing:derive-time-rates', ['--workspace' => $this->workspace->public_id])
            ->assertSuccessful();

        // Amount and currency are one pair; keeping the old currency would
        // mislabel the money.
        $entry->refresh();
        $this->assertSame(37500, $entry->billing_rate_amount);
        $this->assertSame('EUR', $entry->currency);
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
