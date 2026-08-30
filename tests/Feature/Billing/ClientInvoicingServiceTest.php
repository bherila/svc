<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\ClientInvoicingService;
use App\Support\Billing\InvoiceKind;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * What the generator decides, as opposed to what it calculates.
 *
 * The arithmetic is covered by InvoicingExamplesTest and the calculator suites.
 * These are the orchestration rules: which invoices should exist, which must
 * never be written twice, and which periods should produce nothing at all.
 * Nearly every case here is a double-billing guard, because that is the failure
 * mode a client notices.
 */
final class ClientInvoicingServiceTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::query()->create(['name' => 'Invoicing', 'slug' => 'invoicing']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Orchestration Client', 'slug' => 'orchestration-client',
        ]);
        $this->project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Project',
        ]);
        $this->user = User::factory()->create();
    }

    public function test_an_invoice_reconciles_the_work_period_and_sells_the_next_cycle(): void
    {
        $this->monthlyAgreement();
        $this->entry('2024-02-14', 60);

        $invoice = $this->generate('2024-02-01', '2024-02-29');

        $this->assertSame('2024-02-01', $invoice->service_period_start?->toDateString());
        $this->assertSame('2024-02-29', $invoice->service_period_end?->toDateString());
        // The retainer being sold is March, not the February work being settled.
        $this->assertSame('2024-03-01', $invoice->cycle_start?->toDateString());
        $this->assertSame('2024-03-31', $invoice->cycle_end?->toDateString());
        $this->assertSame(InvoiceKind::CadencePeriod->value, $invoice->invoiceKindValue());
    }

    public function test_regenerating_a_draft_does_not_bill_the_same_work_twice(): void
    {
        $this->monthlyAgreement();
        $this->entry('2024-02-14', 300);

        $first = $this->generate('2024-02-01', '2024-02-29');
        $firstTotal = (int) $first->total_amount;
        $firstNumber = $first->invoice_number;
        $lineCount = $first->lines()->count();

        $second = $this->generate('2024-02-01', '2024-02-29');

        $this->assertSame($first->id, $second->id, 'Regeneration must refresh the draft, not create a second invoice');
        $this->assertSame($lineCount, $second->lines()->count());
        $this->assertSame($firstTotal, (int) $second->total_amount);
        $this->assertSame(1, ClientInvoice::query()->count());

        // The counter is monotonic, so re-deriving the number on refresh would
        // burn one on every regeneration.
        $this->assertSame($firstNumber, $second->invoice_number);
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'invoice.generated')->count());
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'invoice.updated')->count());
        $this->assertTrue(ClientCompanyActivity::query()->get()->every(
            fn (ClientCompanyActivity $activity): bool => $activity->workspace_id === $this->workspace->id
                && $activity->client_company_id === $this->company->id
                && $activity->subject_public_id === $first->public_id,
        ));
    }

    public function test_a_settled_invoice_refuses_to_be_rewritten(): void
    {
        $this->monthlyAgreement();
        $this->entry('2024-02-14', 120);

        $invoice = $this->generate('2024-02-01', '2024-02-29');
        $invoice->forceFill(['status' => 'paid'])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be modified');

        $this->generate('2024-02-01', '2024-02-29');
    }

    public function test_an_overlapping_period_is_refused(): void
    {
        $this->monthlyAgreement();
        $this->generate('2024-02-01', '2024-02-29');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('overlapping period');

        $this->generate('2024-02-15', '2024-03-15');
    }

    /**
     * An ad-hoc invoice is not tied to any agreement cycle, so a one-off charge
     * raised mid-period must not block that period's ordinary invoice.
     */
    public function test_an_ad_hoc_invoice_does_not_block_cadence_generation(): void
    {
        $this->monthlyAgreement();

        ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'invoice_number' => 'SVC-AD-HOC',
            'currency' => 'USD',
            'status' => 'issued',
            'invoice_kind' => InvoiceKind::AdHoc->value,
            'service_period_start' => '2024-02-05',
            'service_period_end' => '2024-02-20',
        ]);

        $invoice = $this->generate('2024-02-01', '2024-02-29');

        $this->assertSame(InvoiceKind::CadencePeriod->value, $invoice->invoiceKindValue());
    }

    /**
     * No retainer to sell and nothing billable to report means there is nothing
     * to invoice. Creating an empty draft would consume a number and inflate
     * every count the operator reads.
     */
    public function test_a_cycle_with_no_retainer_and_no_activity_produces_no_invoice(): void
    {
        ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Hourly only',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => Carbon::now()->startOfMonth()->subMonths(2)->toDateString(),
            'retainer_minutes' => 0,
            'retainer_amount' => 0,
            'hourly_rate_amount' => 20000,
            'billing_cadence' => 'monthly',
            'rollover_months' => 0,
        ]);

        $results = app(ClientInvoicingService::class)->generateAllInvoices($this->company);

        $this->assertSame(0, ClientInvoice::query()->count());
        $this->assertGreaterThan(0, $results['summary']['zero_activity_skipped_count']);
        $this->assertSame(0, $results['summary']['generated_count']);
    }

    /**
     * The same agreement with one entry has something to say, so it invoices.
     */
    public function test_a_cycle_with_no_retainer_but_real_work_still_invoices(): void
    {
        ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Hourly only',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => Carbon::now()->startOfMonth()->subMonths(2)->toDateString(),
            'retainer_minutes' => 0,
            'retainer_amount' => 0,
            'hourly_rate_amount' => 20000,
            'billing_cadence' => 'monthly',
            'rollover_months' => 0,
        ]);
        $this->entry(Carbon::now()->startOfMonth()->subMonth()->addDays(4)->toDateString(), 90);

        app(ClientInvoicingService::class)->generateAllInvoices($this->company);

        $this->assertGreaterThan(0, ClientInvoice::query()->count());
    }

    /**
     * A skipped period must not leave a gap in the numbering, because a gap
     * reads as a deleted or hidden invoice to anyone reconciling the sequence.
     */
    public function test_a_skipped_period_leaves_no_hole_in_the_invoice_numbering(): void
    {
        ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Hourly only',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => Carbon::now()->startOfMonth()->subMonths(3)->toDateString(),
            'retainer_minutes' => 0,
            'retainer_amount' => 0,
            'hourly_rate_amount' => 20000,
            'billing_cadence' => 'monthly',
            'rollover_months' => 0,
        ]);
        // Work in the most recent month only; the earlier cycles are empty.
        $this->entry(Carbon::now()->startOfMonth()->subMonth()->addDays(3)->toDateString(), 120);

        app(ClientInvoicingService::class)->generateAllInvoices($this->company);

        $numbers = ClientInvoice::query()->orderBy('id')->pluck('invoice_number')->all();
        $this->assertNotEmpty($numbers);
        $this->assertSame(['SVC-00001'], array_slice($numbers, 0, 1));
    }

    /**
     * Past termination there is no retainer left to sell, and outstanding
     * deferred work is force-billed rather than left permanently unbilled.
     */
    public function test_a_post_termination_invoice_sells_no_retainer_and_force_bills_deferred_work(): void
    {
        $agreement = $this->monthlyAgreement(endsOn: '2024-02-29');
        $this->entry('2024-03-10', 120, deferred: true);

        // Passed explicitly: a terminated agreement is not the company's active
        // one, so the trailing invoice is generated against the terms the work
        // was performed under rather than against nothing.
        $invoice = $this->generate('2024-03-01', '2024-03-31', $agreement);
        $lines = $invoice->lines()->get();

        $this->assertNull($lines->firstWhere('type', 'retainer'), 'No retainer is sold after termination');
        $this->assertSame(0.0, (float) $invoice->retainer_hours_included);

        $deferredLine = $lines->firstWhere('type', 'additional_hours');
        $this->assertNotNull($deferredLine, 'Outstanding deferred work is billed on termination');
        $this->assertSame(2.0, (float) $deferredLine->hours);
    }

    /**
     * Deferred work inside an active agreement draws on leftover capacity at no
     * charge - the client agreed to have it held, not to pay for holding it.
     */
    public function test_deferred_work_draws_on_remaining_capacity_at_no_charge(): void
    {
        $this->monthlyAgreement();
        $this->entry('2024-02-14', 60, deferred: true);

        $invoice = $this->generate('2024-02-01', '2024-02-29');
        $draw = $invoice->lines()->where('type', 'prior_month_retainer')->get();

        $this->assertTrue($draw->isNotEmpty());
        $this->assertSame(0, (int) $draw->sum('total_amount'));
    }

    public function test_a_quarterly_agreement_produces_one_cadence_invoice_per_cycle(): void
    {
        ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Quarterly retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2024-01-01',
            'ends_on' => '2024-09-30',
            'billing_cadence' => 'quarterly',
            'retainer_minutes' => 600,
            'retainer_amount' => 150000,
            'period_retainer_minutes' => 1800,
            'period_retainer_amount' => 450000,
            'hourly_rate_amount' => 20000,
            'rollover_months' => 0,
        ]);

        $this->travelTo(Carbon::parse('2024-07-15'));
        $results = app(ClientInvoicingService::class)->generateAllInvoices($this->company);

        $cadenceInvoices = ClientInvoice::query()
            ->where('invoice_kind', InvoiceKind::CadencePeriod->value)
            ->get();

        $this->assertGreaterThan(0, $cadenceInvoices->count());
        $this->assertSame(0, $results['summary']['interim_invoices_created'], 'Interim billing is off for this agreement');

        // Each cycle is sold exactly once.
        $cycles = $cadenceInvoices->map(fn (ClientInvoice $i): string => $i->cycle_start?->toDateString().'..'.$i->cycle_end?->toDateString());
        $this->assertSame($cycles->count(), $cycles->unique()->count(), 'No retainer cycle may be sold twice');
    }

    /**
     * Generating twice over the whole history must converge: the second run
     * refreshes drafts and reports nothing new.
     */
    public function test_generating_the_whole_history_twice_is_idempotent(): void
    {
        $this->monthlyAgreement(startsOn: Carbon::now()->startOfMonth()->subMonths(3)->toDateString());
        $this->entry(Carbon::now()->startOfMonth()->subMonths(2)->addDays(5)->toDateString(), 240);

        $service = app(ClientInvoicingService::class);
        $service->generateAllInvoices($this->company);

        $countAfterFirst = ClientInvoice::query()->count();
        $linesAfterFirst = DB::table('client_invoice_lines')->count();
        $pivotAfterFirst = DB::table('client_invoice_line_time_entries')->count();

        $service->generateAllInvoices($this->company);

        $this->assertSame($countAfterFirst, ClientInvoice::query()->count());
        $this->assertSame($linesAfterFirst, DB::table('client_invoice_lines')->count());
        $this->assertSame($pivotAfterFirst, DB::table('client_invoice_line_time_entries')->count());
    }

    public function test_month_end_generation_stops_at_the_next_calendar_month(): void
    {
        $this->monthlyAgreement(startsOn: '2026-06-01');
        $this->travelTo(Carbon::parse('2026-08-31 12:00:00'));

        app(ClientInvoicingService::class)->generateAllInvoices($this->company);

        $cadenceInvoices = ClientInvoice::query()
            ->where('workspace_id', $this->workspace->id)
            ->where('invoice_kind', InvoiceKind::CadencePeriod->value)
            ->orderBy('cycle_start')
            ->get();

        $this->assertNotEmpty($cadenceInvoices);
        $this->assertSame('2026-09-01', $cadenceInvoices->last()?->cycle_start?->toDateString());
        $this->assertSame('2026-08-01', $cadenceInvoices->last()?->service_period_start?->toDateString());
        $this->assertFalse(
            $cadenceInvoices->contains(
                static fn (ClientInvoice $invoice): bool => $invoice->cycle_start?->gte(Carbon::parse('2026-10-01')) === true,
            ),
            'August 31 must not overflow into generation of an October retainer.',
        );
    }

    public function test_generation_keeps_project_scoped_successor_chains_independent(): void
    {
        $otherProject = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'name' => 'Concurrent Project',
        ]);
        $outgoing = $this->scopedMonthlyAgreement($this->project, 'Outgoing', '2024-01-01', '2024-01-31');
        $this->scopedMonthlyAgreement($otherProject, 'Concurrent', '2024-03-01');
        $this->scopedMonthlyAgreement($this->project, 'Replacement', '2024-06-01');
        $gapWork = $this->entry('2024-04-15', 120);

        $this->travelTo(Carbon::parse('2024-06-15'));
        app(ClientInvoicingService::class)->generateAllInvoices($this->company);

        $billedByAgreement = $gapWork->refresh()
            ->invoiceLines()
            ->with('invoice')
            ->get()
            ->pluck('invoice.client_agreement_id')
            ->unique()
            ->values()
            ->all();

        $this->assertSame(
            [$outgoing->id],
            $billedByAgreement,
            'An unrelated project agreement must not truncate the outgoing project\'s catch-up segment.',
        );
    }

    public function test_generation_refuses_a_broken_project_chain_before_writing_any_invoice(): void
    {
        $this->monthlyAgreement(endsOn: '2024-01-31');
        $otherCompany = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Other Client',
            'slug' => 'generation-other-client',
        ]);
        $otherProject = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $otherCompany->id,
            'name' => 'Other Project',
        ]);
        ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $otherProject->id,
            'user_id' => $this->user->id,
            'worked_on' => '2024-04-15',
            'minutes' => 120,
            'description' => 'Broken catch-up work',
            'is_billable' => true,
            'is_deferred' => false,
            'status' => 'approved',
            'currency' => 'USD',
        ]);

        $this->travelTo(Carbon::parse('2024-06-15'));

        try {
            app(ClientInvoicingService::class)->generateAllInvoices($this->company);
            $this->fail('Generation must stop before writing an invoice from a broken project chain.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('project outside this client company', $exception->getMessage());
        }

        $this->assertDatabaseCount('client_invoices', 0);
        $this->assertDatabaseCount('client_invoice_lines', 0);
        $this->assertDatabaseCount('client_invoice_line_time_entries', 0);
    }

    public function test_generation_refuses_company_time_stored_under_another_workspace(): void
    {
        $this->monthlyAgreement(endsOn: '2024-01-31');
        $otherWorkspace = Workspace::query()->create([
            'name' => 'Generation Other Workspace',
            'slug' => 'generation-other-workspace',
        ]);
        $foreignProject = ClientProject::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'client_company_id' => $this->company->id,
            'name' => 'Malformed Foreign Project',
        ]);
        ClientTimeEntry::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $foreignProject->id,
            'user_id' => $this->user->id,
            'worked_on' => '2024-04-15',
            'minutes' => 120,
            'description' => 'Work hidden by an ordinary workspace scope',
            'is_billable' => true,
            'is_deferred' => false,
            'status' => 'approved',
            'currency' => 'USD',
        ]);

        $this->travelTo(Carbon::parse('2024-06-15'));

        try {
            app(ClientInvoicingService::class)->generateAllInvoices($this->company);
            $this->fail('Generation must detect a company row claiming another workspace.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('project outside this client company', $exception->getMessage());
        }

        $this->assertDatabaseCount('client_invoices', 0);
        $this->assertDatabaseCount('client_invoice_lines', 0);
        $this->assertDatabaseCount('client_invoice_line_time_entries', 0);
    }

    /**
     * A partial range inside a non-monthly cycle has no defined retainer, so it
     * is refused rather than guessed at.
     */
    public function test_a_partial_range_inside_a_quarterly_cycle_is_refused(): void
    {
        ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Quarterly retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2024-01-01',
            'billing_cadence' => 'quarterly',
            'retainer_minutes' => 600,
            'retainer_amount' => 150000,
            'hourly_rate_amount' => 20000,
            'rollover_months' => 0,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not supported');

        $this->generate('2024-02-01', '2024-02-29');
    }

    /**
     * Work released by a regeneration must come free, or the next pass finds it
     * already billed and silently drops it.
     */
    public function test_regeneration_releases_and_reclaims_the_same_time(): void
    {
        $this->monthlyAgreement();
        $entry = $this->entry('2024-02-14', 180);

        $this->generate('2024-02-01', '2024-02-29');
        $this->assertTrue($entry->refresh()->invoiceLines()->exists());

        $this->generate('2024-02-01', '2024-02-29');
        $this->assertTrue($entry->refresh()->invoiceLines()->exists(), 'The entry must be re-linked, not orphaned');
        $this->assertSame(1, $entry->invoiceLines()->count(), 'And linked to exactly one line');
    }

    private function generate(string $from, string $to, ?ClientAgreement $agreement = null): ClientInvoice
    {
        return app(ClientInvoicingService::class)->generateInvoice(
            $this->company,
            Carbon::parse($from),
            Carbon::parse($to),
            $agreement,
        )->refresh();
    }

    private function monthlyAgreement(string $startsOn = '2024-01-01', ?string $endsOn = null): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Monthly retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'retainer_minutes' => 600,
            'retainer_amount' => 150000,
            'catch_up_threshold_minutes' => 60,
            'hourly_rate_amount' => 20000,
            'billing_cadence' => 'monthly',
            'rollover_months' => 2,
        ]);
    }

    private function scopedMonthlyAgreement(
        ClientProject $project,
        string $title,
        string $startsOn,
        ?string $endsOn = null,
    ): ClientAgreement {
        return ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $project->id,
            'title' => $title,
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'retainer_minutes' => 600,
            'retainer_amount' => 150000,
            'catch_up_threshold_minutes' => 60,
            'hourly_rate_amount' => 20000,
            'billing_cadence' => 'monthly',
            'rollover_months' => 2,
        ]);
    }

    private function entry(string $workedOn, int $minutes, bool $deferred = false): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id,
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
