<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientAgreementRecurringItem;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\Balances\ClosingBalance;
use App\Services\Billing\Balances\MonthSummary;
use App\Services\Billing\InvoiceLedgerBuilder;
use App\Services\Billing\RecurringItemBiller;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Defects the port inherited rather than introduced.
 *
 * Each of these behaves identically in the predecessor, so none of them is a
 * transcription slip - they are places where the original was wrong and the
 * port was faithful. Fixing them changes what clients are billed relative to
 * what they were actually billed, which is why they are pinned here: the tests
 * are the record of what changed and why it was worth changing.
 */
final class InheritedBillingDefectsTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::query()->create(['name' => 'Inherited', 'slug' => 'inherited']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Inherited Client', 'slug' => 'inherited-client',
        ]);
        $this->user = User::factory()->create();
    }

    /**
     * Deferred work is billed only when the allocator finds the whole entry
     * fits. Counting it in the ledger up front consumes pool it may never take,
     * which shows up as a negative balance and bills catch-up hours to restore
     * capacity that nothing actually used.
     */
    public function test_deferred_time_does_not_consume_retainer_pool_before_it_is_allocated(): void
    {
        $project = $this->project('Main');
        $agreement = $this->agreement();

        $this->entry($project, '2024-01-10', 300, deferred: false);
        $this->entry($project, '2024-01-20', 300, deferred: true);

        $ledger = (new InvoiceLedgerBuilder)->buildAgreementLedgerThrough(
            $this->company,
            $agreement,
            Carbon::parse('2024-01-31'),
            false,
        );

        $hours = array_sum(array_map(static fn ($m): float => $m->hoursWorked, $ledger));

        $this->assertSame(5.0, $hours, 'Only the non-deferred 5h has drawn on the retainer');
        $this->assertSame(5.0, $this->closing($ledger, '2024-01')->unusedHours, 'The rest of the pool is still free');
        $this->assertSame(0.0, $this->closing($ledger, '2024-01')->negativeBalance);
    }

    /**
     * Two project-scoped agreements each carry their own retainer. Pooling the
     * company's work across both makes each ledger count hours the other is
     * paying for, so both over-report and neither balance is real.
     */
    public function test_a_project_scoped_agreement_counts_only_its_own_project(): void
    {
        $mine = $this->project('Mine');
        $theirs = $this->project('Theirs');
        $agreement = $this->agreement($mine);

        $this->entry($mine, '2024-01-10', 180);
        $this->entry($theirs, '2024-01-11', 600);

        $ledger = (new InvoiceLedgerBuilder)->buildAgreementLedgerThrough(
            $this->company,
            $agreement,
            Carbon::parse('2024-01-31'),
            false,
        );

        $hours = array_sum(array_map(static fn ($m): float => $m->hoursWorked, $ledger));

        $this->assertSame(3.0, $hours, 'The other project draws on its own agreement, not this one');
    }

    /**
     * A company-wide agreement is unchanged: with no project set, everything the
     * company did still counts.
     */
    public function test_a_company_wide_agreement_still_counts_every_project(): void
    {
        $first = $this->project('First');
        $second = $this->project('Second');
        $agreement = $this->agreement();

        $this->entry($first, '2024-01-10', 180);
        $this->entry($second, '2024-01-11', 120);

        $ledger = (new InvoiceLedgerBuilder)->buildAgreementLedgerThrough(
            $this->company,
            $agreement,
            Carbon::parse('2024-01-31'),
            false,
        );

        $this->assertSame(5.0, array_sum(array_map(static fn ($m): float => $m->hoursWorked, $ledger)));
    }

    /**
     * An item running since January, billed on a cycle that opens mid-month, must
     * not charge the window's start for an anchor the previous cycle covered.
     */
    public function test_a_mid_month_cycle_does_not_rebill_an_anchor_the_last_cycle_covered(): void
    {
        $agreement = $this->agreement();
        $item = $this->recurringItem($agreement, effectiveOn: '2024-01-01', anchorDay: 1);

        $lines = (new RecurringItemBiller)->linesForCycle(
            $agreement->fresh(['recurringItems']),
            Carbon::parse('2024-05-15'),
            Carbon::parse('2024-08-14'),
        );

        $dates = array_map(static fn (array $line): string => $line['line_date']->toDateString(), $lines);

        $this->assertSame(['2024-06-01', '2024-07-01', '2024-08-01'], $dates, '1 May belonged to the previous cycle');
        $this->assertNotContains('2024-05-15', $dates);

        // Unused so PHPStan sees the fixture is deliberate.
        $this->assertNotNull($item->id);
    }

    /**
     * The item's own opening month still falls back, because there is no earlier
     * anchor for it to duplicate.
     */
    public function test_an_items_first_month_still_bills_from_its_start_date(): void
    {
        $agreement = $this->agreement();
        $this->recurringItem($agreement, effectiveOn: '2024-05-10', anchorDay: 1);

        $lines = (new RecurringItemBiller)->linesForCycle(
            $agreement->fresh(['recurringItems']),
            Carbon::parse('2024-05-10'),
            Carbon::parse('2024-07-31'),
        );

        $dates = array_map(static fn (array $line): string => $line['line_date']->toDateString(), $lines);

        $this->assertSame(['2024-05-10', '2024-06-01', '2024-07-01'], $dates);
    }

    /**
     * @param  array<int, MonthSummary>  $ledger
     */
    private function closing(array $ledger, string $yearMonth): ClosingBalance
    {
        foreach ($ledger as $summary) {
            if ($summary->yearMonth === $yearMonth) {
                return $summary->closing;
            }
        }

        $this->fail("No ledger month for {$yearMonth}.");
    }

    private function project(string $name): ClientProject
    {
        return ClientProject::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'name' => $name,
        ]);
    }

    private function agreement(?ClientProject $project = null): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $project?->id,
            'title' => 'Retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2024-01-01',
            'retainer_minutes' => 600,
            'retainer_amount' => 150000,
            'hourly_rate_amount' => 20000,
            'rollover_months' => 0,
        ]);
    }

    private function recurringItem(ClientAgreement $agreement, string $effectiveOn, int $anchorDay): ClientAgreementRecurringItem
    {
        return ClientAgreementRecurringItem::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_agreement_id' => $agreement->id,
            'description' => 'Hosting',
            'amount' => 5000,
            'currency' => 'USD',
            'quantity' => '1',
            'charge_cadence' => 'monthly',
            'anchor_day' => $anchorDay,
            'effective_on' => $effectiveOn,
            'is_active' => true,
        ]);
    }

    private function entry(ClientProject $project, string $workedOn, int $minutes, bool $deferred = false): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $project->id,
            'user_id' => $this->user->id,
            'worked_on' => $workedOn,
            'minutes' => $minutes,
            'description' => 'Work',
            'is_billable' => true,
            'is_deferred' => $deferred,
            'status' => 'approved',
            'currency' => 'USD',
        ]);
    }
}
