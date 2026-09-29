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
use App\Services\Billing\ClientInvoicingService;
use App\Support\Billing\InvoiceLineType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A draft being rebuilt releases its allocations before anything is measured,
 * on every cadence.
 *
 * The draft carries deferred work it absorbed earlier; more ordinary work has
 * since been approved, so on the rebuild the deferred work no longer fits and
 * is released. The rebuilt invoice's balances must describe the work it now
 * carries - not the hours it just dropped. Bulk generation builds its ledger
 * before it reaches the draft, so the release has to come first there too.
 */
final class DraftRebuildMeasuresAfterReleaseTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{string, string, string, string, int, float}> */
    public static function cadences(): iterable
    {
        // cadence, today, work start, work end, ordinary minutes, unused hours after
        yield 'monthly' => ['monthly', '2026-02-10', '2026-01-01', '2026-01-31', 480, 2.0];
        yield 'quarterly' => ['quarterly', '2026-04-10', '2026-01-01', '2026-03-31', 1500, 5.0];
    }

    #[DataProvider('cadences')]
    public function test_a_rebuilt_draft_is_measured_without_the_deferred_work_it_releases(
        string $cadence,
        string $today,
        string $workStart,
        string $workEnd,
        int $ordinaryMinutes,
        float $unusedAfter,
    ): void {
        $workspace = Workspace::query()->create(['name' => 'Rebuild '.$cadence, 'slug' => 'rebuild-'.$cadence]);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Harbor Metrics', 'slug' => 'harbor-metrics']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Metrics']);
        $user = User::factory()->create();
        ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Retainer',
            'status' => 'active', 'currency' => 'USD', 'starts_on' => '2026-01-01',
            'retainer_minutes' => 600, 'retainer_amount' => 150000, 'catch_up_threshold_minutes' => 60,
            'hourly_rate_amount' => 15000, 'billing_cadence' => $cadence, 'rollover_months' => 1,
        ]);
        $entry = fn (int $minutes, bool $deferred): ClientTimeEntry => ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id,
            'user_id' => $user->id, 'worked_on' => Carbon::parse($workStart)->addDays(10)->toDateString(),
            'minutes' => $minutes, 'description' => 'Work', 'is_billable' => true, 'is_deferred' => $deferred,
            'status' => 'approved', 'billing_rate_amount' => 15000, 'currency' => 'USD',
        ]);
        $this->travelTo(Carbon::parse($today.' 12:00:00'));
        $service = app(ClientInvoicingService::class);

        $entry($ordinaryMinutes, false);
        $service->generateAllInvoices($company);
        $draft = ClientInvoice::query()->where('workspace_id', $workspace->id)
            ->whereDate('service_period_start', $workStart)->whereDate('service_period_end', $workEnd)->sole();

        // What an earlier rebuild left on the draft: deferred work absorbed
        // against the reconciled period, which will not fit again.
        $deferred = $entry(600, true);
        $line = ClientInvoiceLine::query()->create([
            'workspace_id' => $workspace->id, 'client_invoice_id' => $draft->id, 'client_agreement_id' => $draft->client_agreement_id,
            'type' => InvoiceLineType::PriorMonthRetainer->value, 'description' => 'Deferred work items applied to retainer (10:00)',
            'quantity' => '0', 'unit_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0, 'hours' => 10,
            'line_date' => $workEnd, 'sort_order' => 90,
        ]);
        $line->timeEntries()->attach($deferred->id, ['workspace_id' => $workspace->id]);

        $service->generateAllInvoices($company);

        $rebuilt = $draft->fresh();
        // Released from this draft. (A later period with room may take it,
        // which is the carry-forward working as intended.)
        $this->assertSame(0, $deferred->invoiceLines()->where('client_invoice_id', $draft->id)->count(), 'The deferred work no longer fits here and is released');
        $this->assertSame($unusedAfter, (float) $rebuilt?->unused_hours_balance);
        $this->assertSame(0.0, (float) $rebuilt?->negative_hours_balance);
    }
}
