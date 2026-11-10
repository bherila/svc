<?php

namespace Tests\Feature\Billing;

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
use App\Support\Billing\InvoiceLineType;
use App\Support\Billing\InvoiceStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Issue #699: an agreement ends at a period close, 30 September 2026, and its
 * successor for the same scope starts the next day.
 *
 * Each cadence invoice reconciles one work period and sells the next cycle in
 * advance, so the successor's first invoice is dated over September - the
 * outgoing agreement's last work period - to sell October (Q4 on a quarterly
 * agreement). The overlap guard measured it by those dates and refused it
 * against the outgoing final invoice, so the successor's first cycle was
 * never sold. That invoice bills no ordinary work from before the successor's
 * start; it only sells.
 */
final class SuccessorFirstCycleTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-09 12:00:00');
        CarbonImmutable::setTestNow('2026-11-09 12:00:00');
        $this->workspace = Workspace::query()->create(['name' => 'Synthetic successor first cycle', 'slug' => 'synthetic-successor-first-cycle']);
        $this->owner = User::factory()->create();
        $this->workspace->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $this->company = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic successor client', 'slug' => 'synthetic-successor-client']);
        $this->project = ClientProject::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Synthetic successor project']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /** @return iterable<string, array{string, string, string, list<string>}> cadence, the outgoing final work period, the successor's first cycle, the outgoing agreement's cycles */
    public static function cadences(): iterable
    {
        yield 'monthly' => ['monthly', '2026-09-01', '2026-10-01', ['2026-01-01', '2026-02-01', '2026-03-01', '2026-04-01', '2026-05-01', '2026-06-01', '2026-07-01', '2026-08-01', '2026-09-01']];
        yield 'quarterly' => ['quarterly', '2026-07-01', '2026-10-01', ['2026-01-01', '2026-04-01', '2026-07-01']];
    }

    /** @param list<string> $outgoingCycles */
    #[DataProvider('cadences')]
    public function test_the_successor_sells_its_first_cycle_after_a_handover_at_a_period_close(string $cadence, string $finalWork, string $firstCycle, array $outgoingCycles): void
    {
        $outgoing = $this->agreement($cadence, '2026-01-01', 60000, 10000);
        $september = $this->hours('2026-09-10', 900);
        app(AgreementWorkflow::class)->terminate($outgoing, '2026-09-30');
        $successor = $this->agreement($cadence, '2026-10-01', 70000, 5000);
        $october = $this->hours('2026-10-12', 60);

        $this->assertSame([], $this->refusals(app(ClientInvoicingService::class)->generateAllInvoices($this->company->refresh())), 'The walk refused a period.');

        // Both invoices are dated over the outgoing final work period: the
        // outgoing one reconciles it, the successor's sells its first cycle.
        $final = $this->cadenceInvoice($outgoing, $finalWork);
        $first = $this->cadenceInvoice($successor, $finalWork);
        $this->assertSame($firstCycle, $first->cycle_start?->toDateString());
        $this->assertSame([], $this->allocated($first), 'The successor bills no work from before its start.');
        $this->assertContains($september->id, $this->allocated($final), 'The outgoing agreement settles its own final period.');

        $drafts = ClientInvoice::query()->where('workspace_id', $this->workspace->id)->where('status', InvoiceStatus::Draft->value)
            ->whereDate('issue_date', '<=', '2026-11-09')->orderBy('service_period_start')->orderBy('id')->get();
        foreach ($drafts as $draft) {
            app(InvoiceLifecycleService::class)->issue($draft->fresh() ?? $draft, $this->workspace);
        }
        // A second walk sells nothing again and refuses nothing.
        $this->assertSame([], $this->refusals(app(ClientInvoicingService::class)->generateAllInvoices($this->company->refresh())));

        $this->assertSame($outgoingCycles, $this->sold($outgoing), 'The outgoing agreement sells each of its cycles once, and none after its end.');
        $sold = $this->sold($successor);
        $this->assertSame($firstCycle, $sold[0] ?? null, 'The successor sells its first cycle.');
        $this->assertSame(array_values(array_unique($sold)), $sold, 'The successor sells no cycle twice.');
        $this->assertContains($september->id, $this->allocatedBy($outgoing));
        $this->assertNotContains($september->id, $this->allocatedBy($successor));
        $this->assertContains($october->id, $this->allocatedBy($successor), 'Work from the successor\'s start is the successor\'s.');
        $this->assertNotContains($october->id, $this->allocatedBy($outgoing));
    }

    /**
     * Only an invoice that bills nothing stands aside. One of the outgoing
     * agreement's dated past its end that charges something - history nobody
     * can explain - still refuses the successor's period; one there carrying
     * nothing, an emptied draft refreshed after the termination (#689), does
     * not, and nobody has to discard it. An invoice naming no agreement still
     * refuses the successor's opening period.
     */
    #[DataProvider('cadences')]
    public function test_only_an_invoice_billing_nothing_stands_aside_for_the_successor(string $cadence, string $finalWork, string $firstCycle): void
    {
        $outgoing = $this->agreement($cadence, '2026-01-01', 60000, 10000);
        app(AgreementWorkflow::class)->terminate($outgoing, '2026-09-30');
        $successor = $this->agreement($cadence, '2026-10-01', 70000, 5000);
        $months = $cadence === 'monthly' ? 1 : 3;
        $workFrom = CarbonImmutable::parse($firstCycle);
        $second = $workFrom->addMonthsNoOverflow($months);
        $stray = $this->invoice($outgoing, 'SYN-STRAY', $workFrom->toDateString(), $second->subDay()->toDateString(), 100);

        $this->assertRefused($successor, $second->toDateString(), 'SYN-STRAY');

        $stray->forceFill(['status' => 'draft', 'subtotal_amount' => 0, 'total_amount' => 0, 'balance_amount' => 0])->save();
        $invoice = app(ClientInvoicingService::class)->generateCadencePeriod($this->company, $successor->fresh() ?? $successor, Carbon::parse($second->toDateString()));
        $this->assertSame($workFrom->toDateString(), $invoice->service_period_start?->toDateString());
        $this->assertSame('draft', $stray->fresh()?->status, 'The emptied draft is left as it is.');

        $this->invoice(null, 'SYN-NONE', $finalWork, $workFrom->subDay()->toDateString(), 100);
        $this->assertRefused($successor, $firstCycle, 'SYN-NONE');
    }

    /**
     * Late work (#691) stays inside the handovers. The outgoing agreement's
     * final invoice reconciles all of August though the successor took over
     * on the 16th; an hour of the 20th logged after it was issued is the
     * successor's, which bills it on its next draft. The outgoing agreement,
     * on a retainer, refreshing its emptied drafts past the end, must not
     * defer it into its own pool.
     */
    public function test_late_work_after_a_mid_period_handover_is_the_successors(): void
    {
        $outgoing = $this->agreement('monthly', '2026-01-01', 60000, 10000);
        // Drafted while open, so the outgoing agreement has drafts past its
        // end for every later walk to refresh.
        app(ClientInvoicingService::class)->generateAllInvoices($this->company->refresh());
        app(AgreementWorkflow::class)->terminate($outgoing->fresh() ?? $outgoing, '2026-08-15');
        $successor = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'title' => 'Synthetic hourly successor',
            'status' => 'active', 'starts_on' => '2026-08-16', 'billing_cadence' => 'monthly', 'currency' => 'USD',
            'hourly_rate_amount' => 5000, 'retainer_minutes' => 0, 'retainer_amount' => 0, 'rollover_months' => 0, 'catch_up_threshold_minutes' => 0,
        ]);
        $this->hours('2026-08-05', 900);
        $this->hours('2026-08-18', 60);
        $this->assertSame([], $this->refusals(app(ClientInvoicingService::class)->generateAllInvoices($this->company->refresh())));
        // Everything due is issued but the outgoing agreement's emptied drafts
        // past its end, which the next walk refreshes.
        $drafts = ClientInvoice::query()->where('workspace_id', $this->workspace->id)->where('status', InvoiceStatus::Draft->value)
            ->whereDate('issue_date', '<=', '2026-11-09')->orderBy('service_period_start')->orderBy('id')->get()
            ->reject(fn (ClientInvoice $draft): bool => (int) $draft->client_agreement_id === $outgoing->id && $draft->service_period_start?->toDateString() > '2026-08-15');
        $this->assertTrue(ClientInvoice::query()->where('client_agreement_id', $outgoing->id)->where('status', InvoiceStatus::Draft->value)
            ->whereDate('service_period_start', '>', '2026-08-15')->exists(), 'The outgoing agreement keeps a draft past its end.');
        foreach ($drafts as $draft) {
            app(InvoiceLifecycleService::class)->issue($draft->fresh() ?? $draft, $this->workspace);
        }
        $this->assertSame(InvoiceStatus::Issued->value, $this->cadenceInvoice($outgoing, '2026-08-01')->status);

        Carbon::setTestNow('2026-11-10 12:00:00');
        CarbonImmutable::setTestNow('2026-11-10 12:00:00');
        $late = $this->hours('2026-08-20', 90);
        $this->assertSame([], $this->refusals(app(ClientInvoicingService::class)->generateAllInvoices($this->company->refresh())));

        $this->assertFalse((bool) $late->fresh()?->is_deferred, 'The outgoing agreement deferred the successor\'s late hour into its own pool.');
        $this->assertNotContains($late->id, $this->allocatedBy($outgoing));
        $this->assertContains($late->id, $this->allocatedBy($successor), 'The successor bills its late hour.');
    }

    /**
     * #699, item 4: with no successor, work logged after a non-monthly
     * agreement's end is billed on the ended agreement's next cycle, as on the
     * monthly path - never by selling a retainer - and that draft is an
     * ordinary one on the client's Invoices tab for an owner to review.
     */
    public function test_work_after_a_quarterly_end_with_no_successor_is_drafted_where_an_owner_sees_it(): void
    {
        $agreement = $this->agreement('quarterly', '2026-01-01', 60000, 10000);
        app(AgreementWorkflow::class)->terminate($agreement, '2026-08-15');
        $late = $this->hours('2026-10-12', 60);
        Carbon::setTestNow('2027-01-02 12:00:00');
        CarbonImmutable::setTestNow('2027-01-02 12:00:00');

        $this->assertSame([], $this->refusals(app(ClientInvoicingService::class)->generateAllInvoices($this->company->refresh())));

        $draft = $this->cadenceInvoice($agreement, '2026-10-01');
        $this->assertSame(InvoiceStatus::Draft->value, $draft->status);
        $this->assertContains($late->id, $this->allocated($draft), 'The ended agreement bills the work after its end.');
        $this->assertFalse($draft->lines()->where('type', InvoiceLineType::Retainer->value)->exists(), 'It sells no retainer after the end.');
        $this->assertSame(10000, (int) $draft->total_amount);

        $this->actingAs($this->owner)
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/invoices")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('clients/invoices')
                ->where('invoices', fn (Collection $invoices): bool => $invoices->contains(
                    fn (array $row): bool => $row['invoice_number'] === $draft->invoice_number && $row['status'] === 'draft' && (int) $row['total_amount'] === 10000,
                )));
    }

    /**
     * @param  array{skipped: list<array<string, mixed>>}  $results
     * @return list<string>
     */
    private function refusals(array $results): array
    {
        return array_values(array_map(
            fn (array $row): string => $row['period'].': '.$row['error'],
            array_filter($results['skipped'], fn (array $row): bool => isset($row['error'])),
        ));
    }

    private function assertRefused(ClientAgreement $agreement, string $cycle, string $by): void
    {
        try {
            app(ClientInvoicingService::class)->generateCadencePeriod($this->company, $agreement->fresh() ?? $agreement, Carbon::parse($cycle));
            $this->fail("{$by} did not refuse the period selling {$cycle}.");
        } catch (RuntimeException $refused) {
            $this->assertStringContainsString('overlapping period', $refused->getMessage());
            $this->assertStringContainsString($by, $refused->getMessage());
        }
    }

    private function agreement(string $cadence, string $startsOn, int $retainer, int $rate): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'title' => 'Synthetic agreement from '.$startsOn,
            'status' => 'active', 'starts_on' => $startsOn, 'billing_cadence' => $cadence, 'bill_overage_interim' => false,
            'currency' => 'USD', 'hourly_rate_amount' => $rate, 'retainer_minutes' => 600, 'retainer_amount' => $retainer,
            'rollover_months' => 0, 'catch_up_threshold_minutes' => 0,
        ]);
    }

    private function invoice(?ClientAgreement $agreement, string $number, string $from, string $to, int $total): ClientInvoice
    {
        return ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'client_agreement_id' => $agreement?->id,
            'invoice_number' => $number, 'invoice_kind' => 'cadence_period', 'status' => 'issued', 'currency' => 'USD',
            'subtotal_amount' => $total, 'tax_amount' => 0, 'total_amount' => $total, 'balance_amount' => $total,
            'service_period_start' => $from, 'service_period_end' => $to, 'hours_billed_at_rate' => 0,
        ]);
    }

    private function hours(string $day, int $minutes): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'client_project_id' => $this->project->id,
            'user_id' => $this->owner->id, 'worked_on' => $day, 'minutes' => $minutes, 'description' => 'Synthetic work',
            'status' => 'approved', 'is_billable' => true, 'is_deferred' => false, 'billing_rate_amount' => 10000, 'currency' => 'USD',
        ])->refresh();
    }

    private function cadenceInvoice(ClientAgreement $agreement, string $workFrom): ClientInvoice
    {
        return ClientInvoice::query()->where('workspace_id', $this->workspace->id)->where('client_agreement_id', $agreement->id)
            ->where('invoice_kind', 'cadence_period')->whereDate('service_period_start', $workFrom)
            ->where('status', '!=', InvoiceStatus::Void->value)->sole();
    }

    /** @return list<string> the cycles the agreement's live invoices sell a retainer for */
    private function sold(ClientAgreement $agreement): array
    {
        return array_values(DB::table('client_invoice_lines as line')
            ->join('client_invoices as invoice', 'invoice.id', '=', 'line.client_invoice_id')
            ->where('invoice.workspace_id', $this->workspace->id)->where('invoice.client_agreement_id', $agreement->id)
            ->where('invoice.status', '!=', InvoiceStatus::Void->value)->where('line.type', InvoiceLineType::Retainer->value)
            ->orderBy('invoice.cycle_start')->pluck('invoice.cycle_start')
            ->map(fn ($date): string => Carbon::parse((string) $date)->toDateString())->all());
    }

    /** @return list<int> the time entries the invoice allocates, by the row each was split from */
    private function allocated(ClientInvoice $invoice): array
    {
        return $this->roots($this->allocations()->where('invoice.id', $invoice->id));
    }

    /** @return list<int> */
    private function allocatedBy(ClientAgreement $agreement): array
    {
        return $this->roots($this->allocations()->where('invoice.client_agreement_id', $agreement->id));
    }

    /** @return list<int> */
    private function roots(Builder $allocations): array
    {
        return array_values(array_unique($allocations->pluck('root')->map(fn ($id): int => (int) $id)->all()));
    }

    private function allocations(): Builder
    {
        return DB::table('client_invoice_line_time_entries as allocation')
            ->join('client_invoice_lines as line', 'line.id', '=', 'allocation.client_invoice_line_id')
            ->join('client_invoices as invoice', 'invoice.id', '=', 'line.client_invoice_id')
            ->join('client_time_entries as entry', 'entry.id', '=', 'allocation.client_time_entry_id')
            ->where('invoice.workspace_id', $this->workspace->id)
            ->where('invoice.status', '!=', InvoiceStatus::Void->value)
            ->selectRaw('coalesce(entry.split_from_time_entry_id, entry.id) as root');
    }
}
