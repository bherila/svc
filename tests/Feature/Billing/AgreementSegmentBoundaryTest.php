<?php

namespace Tests\Feature\Billing;

use App\Exceptions\WorkOutsideAgreementSegment;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Engagement\AgreementWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Ordinary work dated after an agreement's segment ends - its termination, or
 * the day before a successor for the same scope starts - is the successor's
 * to bill at its own terms, never the outgoing agreement's, even when the
 * outgoing draft was generated before the history was corrected.
 */
final class AgreementSegmentBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * The successor's cycles run from its own start, so the period that
     * reconciles June is the one its first cycle hands to.
     *
     * @return iterable<string, array{string, string, string, string}> cadence, outgoing draft's period, termination, successor's period
     */
    public static function cadences(): iterable
    {
        yield 'quarterly, terminated mid-quarter' => ['quarterly', '2026-07-01', '2026-05-15', '2026-08-16'];
        yield 'monthly, terminated mid-month' => ['monthly', '2026-07-01', '2026-06-05', '2026-07-06'];
    }

    /**
     * The post-merge review's history: A's draft generated while A was open
     * charges June at A's $200; A is then terminated backdated, and B takes
     * over the next day at $100. The unchanged draft is refused; regenerated,
     * it no longer charges June; and B bills June once, at its own rate.
     */
    #[DataProvider('cadences')]
    public function test_a_backdated_termination_moves_ordinary_work_to_the_successor(string $cadence, string $outgoingPeriod, string $endsOn, string $successorPeriod): void
    {
        Carbon::setTestNow('2026-07-10 12:00:00');
        CarbonImmutable::setTestNow('2026-07-10 12:00:00');
        $workspace = Workspace::query()->create(['name' => 'Synthetic segment', 'slug' => 'synthetic-segment']);
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic segment client', 'slug' => 'synthetic-segment-client']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic segment project']);
        $outgoing = $this->agreement($workspace, $company, $cadence, '2026-01-01', 20000);
        $june = ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id,
            'user_id' => $owner->id, 'worked_on' => '2026-06-10', 'minutes' => 120, 'description' => 'Synthetic June work',
            'status' => 'approved', 'is_billable' => true, 'is_deferred' => false, 'billing_rate_amount' => 20000, 'currency' => 'USD',
        ]);

        $draft = app(ClientInvoicingService::class)->generateCadencePeriod($company, $outgoing, Carbon::parse($outgoingPeriod));
        $this->assertSame(40000, $this->chargedFor($june, $draft), 'Generated while A was open, its draft charges June at $200.');

        app(AgreementWorkflow::class)->terminate($outgoing->fresh() ?? $outgoing, $endsOn);
        $successor = $this->agreement($workspace, $company, $cadence, CarbonImmutable::parse($endsOn)->addDay()->toDateString(), 10000);

        try {
            app(InvoiceLifecycleService::class)->issue($draft->fresh() ?? $draft, $workspace);
            $this->fail('A draft charging work after its agreement ended was issued.');
        } catch (WorkOutsideAgreementSegment $refused) {
            $this->assertSame('work_outside_agreement_segment', $refused->refusalCode());
        }
        $this->assertSame('draft', $draft->fresh()?->status);

        $rebuilt = app(ClientInvoicingService::class)->regenerateDraftInvoice($draft->fresh());
        if ($rebuilt instanceof ClientInvoice) {
            $this->assertSame(0, $this->chargedFor($june, $rebuilt), 'Regenerated, A no longer charges June.');
            app(InvoiceLifecycleService::class)->issue($rebuilt->fresh() ?? $rebuilt, $workspace);
        }

        Carbon::setTestNow('2026-08-20 12:00:00');
        CarbonImmutable::setTestNow('2026-08-20 12:00:00');
        $successorDraft = app(ClientInvoicingService::class)->generateCadencePeriod($company, $successor, Carbon::parse($successorPeriod));
        $this->assertSame(20000, $this->chargedFor($june, $successorDraft), 'B bills June at its own $100.');
        app(InvoiceLifecycleService::class)->issue($successorDraft->fresh() ?? $successorDraft, $workspace);
        $this->assertSame(1, DB::table('client_invoice_line_time_entries')->where('client_time_entry_id', $june->id)->count(), 'June is charged once.');
    }

    private function agreement(Workspace $workspace, ClientCompany $company, string $cadence, string $startsOn, int $rate): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic agreement from '.$startsOn,
            'status' => 'active', 'starts_on' => $startsOn, 'billing_cadence' => $cadence, 'bill_overage_interim' => false,
            'currency' => 'USD', 'hourly_rate_amount' => $rate, 'retainer_minutes' => 0, 'retainer_amount' => 0,
            'rollover_months' => 0, 'catch_up_threshold_minutes' => 0,
        ]);
    }

    /** What the invoice's lines charge for the entry, at their unit price. */
    private function chargedFor(ClientTimeEntry $entry, ClientInvoice $invoice): int
    {
        return (int) DB::table('client_invoice_lines as line')
            ->join('client_invoice_line_time_entries as allocation', 'allocation.client_invoice_line_id', '=', 'line.id')
            ->join('client_time_entries as entry', 'entry.id', '=', 'allocation.client_time_entry_id')
            ->where('line.client_invoice_id', $invoice->id)
            ->whereRaw('coalesce(entry.split_from_time_entry_id, entry.id) = ?', [$entry->id])
            ->sum(DB::raw('line.unit_amount * entry.minutes / 60'));
    }
}
