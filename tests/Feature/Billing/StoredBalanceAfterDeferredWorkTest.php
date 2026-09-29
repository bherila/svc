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

    /**
     * The non-monthly path offers deferred work the hours its ledger covers
     * beyond this cycle's own work, which includes debt carried in from the
     * last cycle (its ledger does not read billed overage). Its stored
     * balances follow the same after-allocation measurement as its statement.
     */
    public function test_quarterly_balances_are_stored_after_the_drafts_deferred_work(): void
    {
        [$company, $agreement, $project, $user] = $this->fixture('quarterly', [['2026-02-10', 2400, false]]);
        $this->travelTo(Carbon::parse('2026-07-02 12:00:00'));
        $service = app(ClientInvoicingService::class);
        $service->generateInvoice($company, Carbon::parse('2026-01-01'), Carbon::parse('2026-03-31'), $agreement);
        foreach ([['2026-04-10', 120, false], ['2026-04-12', 360, true]] as [$workedOn, $minutes, $deferred]) {
            $this->entry($company, $project, $user, $workedOn, $minutes, $deferred);
        }

        $invoice = $service->generateInvoice($company, Carbon::parse('2026-04-01'), Carbon::parse('2026-06-30'), $agreement);
        $statement = $invoice->hoursStatement();
        $this->assertInstanceOf(InvoiceHoursStatement::class, $statement);
        $this->assertSame(6.0, $statement->deferredAppliedHours, 'The carried debt leaves room the deferred work is applied to');

        // 30 retainer hours, 10 owed from the last cycle, 2 ordinary and 6
        // deferred: 12 left, not the 18 measured before the deferred work.
        $this->assertSame(12.0, (float) $invoice->unused_hours_balance);
        $this->assertSame($statement->deficitCarriedForwardHours, (float) $invoice->negative_hours_balance);
        // And the hours the row says it worked include the deferred work, so
        // the row adds up: 30 - 10 owed - 8 worked leaves the 12.
        $this->assertSame(8.0, (float) $invoice->hours_worked);
    }

    /**
     * Deferred work that draws on last month's rollover is recorded as
     * rollover used, beside the balances it moved.
     */
    public function test_monthly_rollover_used_includes_the_drafts_deferred_work(): void
    {
        [$company, $agreement, $project, $user] = $this->fixture('monthly', [['2026-01-10', 120, false]]);
        $this->travelTo(Carbon::parse('2026-03-02 12:00:00'));
        $service = app(ClientInvoicingService::class);
        $service->generateInvoice($company, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'), $agreement);
        foreach ([['2026-02-10', 600, false], ['2026-02-12', 300, true]] as [$workedOn, $minutes, $deferred]) {
            $this->entry($company, $project, $user, $workedOn, $minutes, $deferred);
        }

        $invoice = $service->generateInvoice($company, Carbon::parse('2026-02-01'), Carbon::parse('2026-02-28'), $agreement);
        $this->assertSame(5.0, $invoice->hoursStatement()?->deferredAppliedHours);

        // February's own 10 hours use its retainer; the 5 deferred hours are
        // drawn from the 8 January left, so 5 rollover hours are used.
        $this->assertSame(5.0, (float) $invoice->rollover_hours_used);
        $this->assertSame(0.0, (float) $invoice->unused_hours_balance);
    }

    /**
     * @param  list<array{string, int, bool}>  $entries
     * @return array{ClientCompany, ClientAgreement, ClientProject, User}
     */
    private function fixture(string $cadence, array $entries = [['2026-01-10', 120, false], ['2026-01-12', 360, true]]): array
    {
        $workspace = Workspace::query()->create(['name' => 'Stored balance', 'slug' => 'stored-balance-'.$cadence]);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Stored balance client', 'slug' => 'stored-balance-client-'.$cadence]);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Stored balance project']);
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Retainer',
            'status' => 'active', 'currency' => 'USD', 'starts_on' => '2026-01-01',
            'retainer_minutes' => 600, 'retainer_amount' => 150000, 'catch_up_threshold_minutes' => 60,
            'hourly_rate_amount' => 15000, 'billing_cadence' => $cadence, 'rollover_months' => 1,
        ]);
        $user = User::factory()->create();
        foreach ($entries as [$workedOn, $minutes, $deferred]) {
            $this->entry($company, $project, $user, $workedOn, $minutes, $deferred);
        }

        return [$company, $agreement, $project, $user];
    }

    private function entry(ClientCompany $company, ClientProject $project, User $user, string $workedOn, int $minutes, bool $deferred): void
    {
        ClientTimeEntry::query()->create([
            'workspace_id' => $company->workspace_id, 'client_company_id' => $company->id, 'client_project_id' => $project->id,
            'user_id' => $user->id, 'worked_on' => $workedOn, 'minutes' => $minutes, 'is_deferred' => $deferred,
            'description' => 'Synthetic work', 'is_billable' => true, 'status' => 'approved',
            'billing_rate_amount' => 15000, 'currency' => 'USD',
        ]);
    }
}
