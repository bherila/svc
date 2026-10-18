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
use App\Services\Billing\DeferredBacklogReader;
use App\Services\Billing\DeferredBillingAllocator;
use App\Services\Billing\InvoiceLineComposer;
use App\Support\Billing\DeferredBacklogIssue;
use App\Support\Billing\SubcontractorBillingMode;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

/**
 * The deferred backlog is the termination collection, priced as termination
 * prices it (#366).
 *
 * One fixture mixes every mode, two project scopes, work that must stay out,
 * and rows of another workspace; for each agreement the backlog's entries must
 * be exactly what {@see DeferredBillingAllocator::collectForTermination()}
 * returns today, and its amount exactly what the termination invoice charges
 * for them.
 */
final class DeferredBacklogParityTest extends TestCase
{
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private ClientProject $otherProject;

    private User $user;

    private User $subcontractor;

    private const string THROUGH = '2026-05-31';

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::query()->create(['name' => 'Backlog', 'slug' => 'backlog-parity']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Backlog Client', 'slug' => 'backlog-client',
        ]);
        $this->project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Backlog Project',
        ]);
        $this->otherProject = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Backlog Other Project',
        ]);
        $this->user = User::factory()->create(['name' => 'Synthetic Consultant']);
        $this->subcontractor = User::factory()->create(['name' => 'Synthetic Subcontractor']);
    }

    public function test_the_backlog_is_the_termination_collection_and_its_charge_for_every_agreement(): void
    {
        $this->mixedFixture();
        $companyWide = $this->agreement('Company-wide', null, 15000);
        $scoped = $this->agreement('Project scoped', $this->project->id, 12000);

        $through = CarbonImmutable::parse(self::THROUGH);
        $backlogs = app(DeferredBacklogReader::class)->forAgreements(
            $this->workspace,
            collect([$companyWide, $scoped]),
            $through,
        );

        foreach ([$companyWide, $scoped] as $agreement) {
            $backlog = $backlogs[$agreement->id];
            $collected = app(DeferredBillingAllocator::class)
                ->collectForTermination($this->company, Carbon::parse(self::THROUGH), $agreement);

            $this->assertNotEmpty($collected->modelKeys(), $agreement->title);
            $this->assertSame($collected->modelKeys(), $backlog->entryIds, $agreement->title);
            $this->assertSame((int) $collected->sum('minutes'), $backlog->minutes, $agreement->title);
            $this->assertSame($collected->count(), $backlog->entryCount, $agreement->title);
            $this->assertSame($collected->first()?->worked_on->toDateString(), $backlog->oldestWorkedOn, $agreement->title);
            $this->assertNull($backlog->issue, $agreement->title);

            [$charged, $hours] = $this->terminationCharge($agreement, $collected);
            $this->assertSame($charged, $backlog->amount, $agreement->title);
            $this->assertSame($hours, round((float) $backlog->agreementRateHours() + (float) $backlog->flatHourlyHours(), 4), $agreement->title);
        }

        // The scope is what differs: the company-wide agreement also carries
        // the other project's hour, which the scoped one must never quote.
        $this->assertSame(60, $backlogs[$companyWide->id]->minutes - $backlogs[$scoped->id]->minutes);
    }

    public function test_one_agreement_reads_the_same_as_many(): void
    {
        $this->mixedFixture();
        $companyWide = $this->agreement('Company-wide', null, 15000);
        $scoped = $this->agreement('Project scoped', $this->project->id, 12000);
        $through = CarbonImmutable::parse(self::THROUGH);
        $reader = app(DeferredBacklogReader::class);

        $many = $reader->forAgreements($this->workspace, collect([$companyWide, $scoped]), $through);

        $this->assertEquals($many[$scoped->id], $reader->forAgreement($this->workspace, $scoped, $through));
        $this->assertSame([], $reader->forAgreements($this->workspace, collect(), $through));
    }

    public function test_a_bounded_number_of_queries_reads_any_number_of_agreements(): void
    {
        $this->mixedFixture();
        $agreements = collect([
            $this->agreement('One', null, 15000),
            $this->agreement('Two', $this->project->id, 12000),
            $this->agreement('Three', $this->otherProject->id, 12000),
        ]);

        DB::enableQueryLog();
        app(DeferredBacklogReader::class)->forAgreements($this->workspace, $agreements, CarbonImmutable::parse(self::THROUGH), ['2026-04', '2026-05']);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // The outside-workspace claim scan, two chain checks (shelf and history),
        // the outstanding set, the agreement
        // segments, and the history with its two eager loads - however many agreements.
        $this->assertLessThanOrEqual(8, $queries);
    }

    public function test_a_disagreeing_project_chain_withholds_the_backlog_where_termination_refuses(): void
    {
        $agreement = $this->agreement('Company-wide', null, 15000);
        $this->entry(60, '2026-03-01');
        $stranger = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Stranger Client', 'slug' => 'stranger-client',
        ]);
        $strangerProject = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $stranger->id, 'name' => 'Stranger Project',
        ]);
        $this->writingLegacyCrossTenantRows(fn () => $this->entry(60, '2026-03-02', ['client_project_id' => $strangerProject->id]));
        // A company whose chain agrees is unaffected in the same read.
        $strangerAgreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $stranger->id,
            'title' => 'Stranger agreement',
            'status' => 'active',
            'currency' => 'USD',
            'hourly_rate_amount' => 10000,
            'starts_on' => '2026-01-01',
        ]);

        $backlogs = app(DeferredBacklogReader::class)->forAgreements(
            $this->workspace,
            collect([$agreement, $strangerAgreement]),
            CarbonImmutable::parse(self::THROUGH),
        );

        $this->assertSame(DeferredBacklogIssue::ProjectChainMismatch, $backlogs[$agreement->id]->issue);
        $this->assertNull($backlogs[$agreement->id]->minutes);
        $this->assertNull($backlogs[$strangerAgreement->id]->issue);
        $this->assertSame(0, $backlogs[$strangerAgreement->id]->minutes);

        $this->expectException(DomainException::class);
        app(DeferredBillingAllocator::class)->collectForTermination($this->company, Carbon::parse(self::THROUGH), $agreement);
    }

    public function test_a_successor_owns_the_work_from_the_day_it_starts(): void
    {
        $outgoing = $this->agreement('Outgoing', null, 15000);
        $outgoing->forceFill(['status' => 'terminated', 'ends_on' => '2026-03-31'])->save();
        $successor = $this->agreement('Successor', null, 18000);
        $successor->forceFill(['starts_on' => '2026-04-01'])->save();
        // Another scope's agreement is no successor of a company-wide one.
        $other = $this->agreement('Other scope', $this->otherProject->id, 12000);
        $other->forceFill(['starts_on' => '2026-03-15'])->save();
        $this->entry(60, '2026-03-10');
        $this->entry(30, '2026-04-10');

        $backlogs = app(DeferredBacklogReader::class)->forAgreements(
            $this->workspace,
            collect([$outgoing, $successor]),
            CarbonImmutable::parse(self::THROUGH),
            ['2026-03', '2026-04'],
        );

        $this->assertSame(60, $backlogs[$outgoing->id]->minutes);
        $this->assertSame('2026-03-31', $backlogs[$outgoing->id]->through);
        $this->assertSame(15000, $backlogs[$outgoing->id]->amount);
        $this->assertSame([60, 0], array_column(array_map(static fn ($m): array => [$m->minutesAdded], $backlogs[$outgoing->id]->trend), 0));
        $this->assertSame(30, $backlogs[$successor->id]->minutes);
        $this->assertSame(self::THROUGH, $backlogs[$successor->id]->through);
        $this->assertSame(9000, $backlogs[$successor->id]->amount);
        // Until the outgoing final invoice links it, termination of the
        // outgoing agreement through the handover collects exactly its share.
        $this->assertSame(
            app(DeferredBillingAllocator::class)->collectForTermination($this->company, Carbon::parse('2026-03-31'), $outgoing)->modelKeys(),
            $backlogs[$outgoing->id]->entryIds,
        );
    }

    public function test_a_terminated_agreement_reads_its_backlog_through_its_own_end(): void
    {
        $agreement = $this->agreement('Ended', null, 15000);
        $agreement->forceFill(['status' => 'terminated', 'ends_on' => '2026-03-31'])->save();
        $this->entry(60, '2026-03-31');
        $this->entry(30, '2026-04-01');

        $backlog = app(DeferredBacklogReader::class)->forAgreement($this->workspace, $agreement, CarbonImmutable::parse(self::THROUGH), ['2026-03', '2026-04']);

        $this->assertSame('2026-03-31', $backlog->through);
        $this->assertSame(60, $backlog->minutes);
        $this->assertSame([60, 0], array_map(static fn ($month): int => $month->minutesAdded, $backlog->trend));
    }

    public function test_an_entry_of_another_workspace_claiming_the_client_withholds_the_backlog(): void
    {
        $agreement = $this->agreement('Company-wide', null, 15000);
        $this->entry(60, '2026-03-01');
        $other = Workspace::query()->create(['name' => 'Claiming tenant', 'slug' => 'backlog-claiming-tenant']);
        $otherCompany = ClientCompany::query()->create(['workspace_id' => $other->id, 'name' => 'Claiming Client', 'slug' => 'claiming-client']);
        $otherProject = ClientProject::query()->create(['workspace_id' => $other->id, 'client_company_id' => $otherCompany->id, 'name' => 'Claiming Project']);
        $this->writingLegacyCrossTenantRows(fn () => ClientTimeEntry::query()->create([
            'workspace_id' => $other->id, 'client_project_id' => $otherProject->id, 'is_deferred' => false,
        ] + $this->attributes(60, '2026-03-02')));

        $backlog = app(DeferredBacklogReader::class)->forAgreement($this->workspace, $agreement, CarbonImmutable::parse(self::THROUGH));

        $this->assertSame(DeferredBacklogIssue::ProjectChainMismatch, $backlog->issue);
        $this->assertNull($backlog->minutes);
    }

    public function test_an_allocated_entry_with_a_disagreeing_chain_withholds_the_trend_too(): void
    {
        $agreement = $this->agreement('Company-wide', null, 15000);
        $stranger = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Allocated Stranger Client', 'slug' => 'allocated-stranger-client',
        ]);
        $strangerProject = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $stranger->id, 'name' => 'Allocated Stranger Project',
        ]);
        $malformed = null;
        $this->writingLegacyCrossTenantRows(function () use (&$malformed, $strangerProject): void {
            $malformed = $this->entry(60, '2026-04-02', ['client_project_id' => $strangerProject->id]);
        });
        $this->allocate($malformed, 'prior_month_retainer', '2026-04-30', $this->workspace);
        $reader = app(DeferredBacklogReader::class);
        $through = CarbonImmutable::parse(self::THROUGH);

        // Nothing is awaiting billing, so the shelf alone looks clean ...
        $this->assertNull($reader->forAgreement($this->workspace, $agreement, $through)->issue);
        // ... but the trend would read the malformed row, so it is withheld.
        $this->assertSame(DeferredBacklogIssue::ProjectChainMismatch, $reader->forAgreement($this->workspace, $agreement, $through, ['2026-04'])->issue);
    }

    public function test_an_agreement_of_another_workspace_is_refused(): void
    {
        $other = Workspace::query()->create(['name' => 'Elsewhere', 'slug' => 'backlog-elsewhere']);
        $agreement = $this->agreement('Company-wide', null, 15000);

        $this->expectException(LogicException::class);

        app(DeferredBacklogReader::class)->forAgreement($other, $agreement, CarbonImmutable::parse(self::THROUGH));
    }

    public function test_the_trend_reads_this_workspaces_movements_inside_the_window(): void
    {
        $agreement = $this->agreement('Company-wide', null, 15000);
        // Worked before the window and absorbed by April's pool.
        $absorbed = $this->entry(30, '2026-02-10');
        $this->allocate($absorbed, 'prior_month_retainer', '2026-04-30', $this->workspace);
        // Worked in May, still waiting.
        $this->entry(45, '2026-05-03');
        // Absorbed before the window: neither added nor applied in it.
        $old = $this->entry(600, '2026-01-10');
        $this->allocate($old, 'prior_month_retainer', '2026-02-28', $this->workspace);

        $backlog = app(DeferredBacklogReader::class)->forAgreement(
            $this->workspace,
            $agreement,
            CarbonImmutable::parse(self::THROUGH),
            ['2026-04', '2026-05'],
        );

        $this->assertSame([['2026-04', 0, 30, 0], ['2026-05', 45, 0, 0]], array_map(
            static fn ($month): array => [$month->period, $month->minutesAdded, $month->minutesApplied, $month->minutesBilled],
            $backlog->trend,
        ));
        $this->assertSame(45, $backlog->minutes);
    }

    /**
     * Every row the backlog must agree with termination about, in or out.
     */
    private function mixedFixture(): void
    {
        // In: consultant work on both projects, retainer-mode subcontractor
        // work, and flat-hourly work at two snapshot rates.
        $this->entry(50, '2026-03-04');
        $this->entry(60, '2026-03-05', ['client_project_id' => $this->otherProject->id]);
        $this->entry(25, '2026-03-01', ['subcontractor_billing_mode' => SubcontractorBillingMode::Retainer->value, 'user_id' => $this->subcontractor->id]);
        $this->entry(40, '2026-02-11', $this->flatHourly(5000));
        $this->entry(35, '2026-02-12', $this->flatHourly(5500));
        $this->entry(15, '2026-04-02', ['status' => 'invoiced']);

        // Out: direct work, ordinary work, non-billable, draft, work after the
        // cutoff, and work already on a line of this workspace.
        $this->entry(90, '2026-03-06', ['subcontractor_billing_mode' => SubcontractorBillingMode::Direct->value, 'user_id' => $this->subcontractor->id, 'subcontractor_cost_amount' => 4000, 'subcontractor_cost_currency' => 'USD']);
        $this->entry(90, '2026-03-07', ['is_deferred' => false]);
        $this->entry(90, '2026-03-08', ['is_billable' => false]);
        $this->entry(90, '2026-03-09', ['status' => 'draft']);
        $this->entry(90, '2026-06-02');
        $this->allocate($this->entry(90, '2026-03-10'), 'prior_month_retainer', '2026-03-31', $this->workspace);

        // Another workspace: its own deferred work, and a legacy pivot from
        // one of this workspace's entries to a line of its own. Termination's
        // reservation check is deliberately unscoped - any link reserves - and
        // the backlog must read it the same way.
        $other = Workspace::query()->create(['name' => 'Other tenant', 'slug' => 'backlog-other-tenant']);
        $otherCompany = ClientCompany::query()->create(['workspace_id' => $other->id, 'name' => 'Other Tenant Client', 'slug' => 'other-tenant-client']);
        $otherProject = ClientProject::query()->create(['workspace_id' => $other->id, 'client_company_id' => $otherCompany->id, 'name' => 'Other Tenant Project']);
        ClientTimeEntry::query()->create([
            'workspace_id' => $other->id, 'client_company_id' => $otherCompany->id, 'client_project_id' => $otherProject->id,
        ] + $this->attributes(600, '2026-03-01'));
        $crossLinked = $this->entry(90, '2026-03-11');
        $this->writingLegacyCrossTenantRows(fn () => $this->allocate($crossLinked, 'time', '2026-03-31', $other, $otherCompany));
    }

    /**
     * Compose the termination lines for the collected entries on a draft and
     * read back what they charge, in minor units and hours.
     *
     * @param  iterable<ClientTimeEntry>  $collected
     * @return array{int, float}
     */
    private function terminationCharge(ClientAgreement $agreement, iterable $collected): array
    {
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_agreement_id' => $agreement->id,
            'invoice_number' => 'SVC-BACKLOG-'.$agreement->id,
            'currency' => 'USD',
            'status' => 'draft',
            'service_period_start' => '2026-05-01',
            'service_period_end' => self::THROUGH,
        ]);
        $sortOrder = 0;

        DB::transaction(function () use ($invoice, $agreement, $collected, &$sortOrder): void {
            app(InvoiceLineComposer::class)->addDeferredTerminationLine($invoice, $agreement, collect($collected), $sortOrder);
        });
        $lines = ClientInvoiceLine::query()->where('client_invoice_id', $invoice->id)->get();
        $charged = (int) $lines->sum('total_amount');
        $hours = round((float) $lines->sum('hours'), 4);
        // Released again so the next agreement's collection is unaffected.
        foreach ($lines as $line) {
            $line->timeEntries()->detach();
            $line->delete();
        }
        $invoice->delete();

        return [$charged, $hours];
    }

    private function agreement(string $title, ?int $projectId, ?int $rate): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $projectId,
            'title' => $title,
            'status' => 'active',
            'currency' => 'USD',
            'hourly_rate_amount' => $rate,
            'starts_on' => '2026-01-01',
        ]);
    }

    /** @return array<string, mixed> */
    private function flatHourly(int $cost): array
    {
        return [
            'user_id' => $this->subcontractor->id,
            'subcontractor_billing_mode' => SubcontractorBillingMode::FlatHourly->value,
            'subcontractor_cost_amount' => $cost,
            'subcontractor_cost_currency' => 'USD',
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function entry(int $minutes, string $workedOn, array $overrides = []): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create($overrides + $this->attributes($minutes, $workedOn));
    }

    /** @return array<string, mixed> */
    private function attributes(int $minutes, string $workedOn): array
    {
        return [
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'worked_on' => $workedOn,
            'minutes' => $minutes,
            'description' => 'Synthetic deferred work',
            'is_billable' => true,
            'is_deferred' => true,
            'status' => 'approved',
            'billing_rate_amount' => 9900,
            'currency' => 'USD',
        ];
    }

    private function allocate(ClientTimeEntry $entry, string $type, string $lineDate, Workspace $workspace, ?ClientCompany $company = null): void
    {
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => ($company ?? $this->company)->id,
            'invoice_number' => 'SVC-ALLOC-'.$entry->id,
            'currency' => 'USD',
            'status' => 'issued',
            'service_period_end' => $lineDate,
        ]);
        $line = ClientInvoiceLine::query()->create([
            'workspace_id' => $workspace->id,
            'client_invoice_id' => $invoice->id,
            'type' => $type,
            'description' => 'Synthetic allocation',
            'quantity' => '1.0000',
            'unit_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'line_date' => $lineDate,
            'sort_order' => 0,
        ]);
        $line->timeEntries()->attach($entry->id, ['workspace_id' => $workspace->id]);
    }
}
