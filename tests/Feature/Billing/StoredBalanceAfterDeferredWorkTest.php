<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\ClientInvoicingService;
use App\Support\Billing\InvoiceHoursStatement;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The balances stored on an invoice describe it after its own deferred work.
 *
 * `unused_hours_balance`, `negative_hours_balance` and the two `starting_*`
 * snapshots were measured before the draft applied its deferred work, so they
 * overstated what rolls forward: the pool the deferred entries drew on still
 * read as unused. The hours statement is measured after, and the stored
 * columns now agree with it (#353).
 *
 * Here: a 10-hour monthly retainer with one month of rollover. January works
 * 2 ordinary hours and holds 6 hours of deferred work, which January's own
 * invoice applies to the 8 hours left, leaving 2 unused - not 8.
 */
final class StoredBalanceAfterDeferredWorkTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_balances_are_stored_after_the_drafts_deferred_work(): void
    {
        [$company, $agreement] = $this->fixture('monthly');
        $this->travelTo(Carbon::parse('2026-02-02 12:00:00'));

        $invoice = app(ClientInvoicingService::class)->generateInvoice(
            $company, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'), $agreement,
        );
        $statement = $invoice->hoursStatement();
        $this->assertInstanceOf(InvoiceHoursStatement::class, $statement);
        $this->assertSame(6.0, $statement->deferredAppliedHours, 'The fixture applies its deferred work here');

        $this->assertSame(2.0, (float) $invoice->unused_hours_balance, 'The 6 deferred hours drew on the 8 left');
        $this->assertSame(0.0, (float) $invoice->negative_hours_balance);
        $this->assertSame($statement->deficitCarriedForwardHours, (float) $invoice->negative_hours_balance);
        $this->assertSame(
            round($statement->rolledForwardHours + $statement->nextRetainerHours, 4),
            (float) $invoice->starting_unused_hours,
            'What the next month opens with, as the statement measures it',
        );
    }

    /** @return array{ClientCompany, ClientAgreement} */
    private function fixture(string $cadence): array
    {
        $workspace = Workspace::query()->create(['name' => 'Stored balance', 'slug' => 'stored-balance-'.$cadence]);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Stored balance client', 'slug' => 'stored-balance-client']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Stored balance project']);
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Retainer',
            'status' => 'active', 'currency' => 'USD', 'starts_on' => '2026-01-01',
            'retainer_minutes' => 600, 'retainer_amount' => 150000, 'catch_up_threshold_minutes' => 60,
            'hourly_rate_amount' => 15000, 'billing_cadence' => $cadence, 'rollover_months' => 1,
        ]);
        $user = User::factory()->create();
        foreach ([['2026-01-10', 120, false], ['2026-01-12', 360, true]] as [$workedOn, $minutes, $deferred]) {
            ClientTimeEntry::query()->create([
                'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id,
                'user_id' => $user->id, 'worked_on' => $workedOn, 'minutes' => $minutes, 'is_deferred' => $deferred,
                'description' => 'Synthetic work', 'is_billable' => true, 'status' => 'approved',
                'billing_rate_amount' => 15000, 'currency' => 'USD',
            ]);
        }

        return [$company, $agreement];
    }
}
