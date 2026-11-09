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
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * The cadence overlap guard (`ClientInvoicingService::assertNoOverlappingInvoice()`)
 * compares a period against invoices whose work the agreement could also
 * reach, on both cadence paths (docs/service-packages.md, slice 4).
 *
 * Every selector a period reconciles reads through the agreement's project
 * scope, so two agreements of one company on two different projects bill the
 * same dates on disjoint work, and each bills its own periods. A company-wide
 * agreement reaches every project's work, so it and a project agreement still
 * refuse each other; so do invoices naming no agreement, or one this company
 * cannot account for.
 */
final class ProjectScopedOverlapGuardTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));
        $this->workspace = Workspace::query()->create(['name' => 'Synthetic overlap scope', 'slug' => 'synthetic-overlap-scope']);
        $this->user = User::factory()->create();
        $this->workspace->memberships()->create(['user_id' => $this->user->id, 'role' => 'owner']);
        $this->company = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic overlap client', 'slug' => 'synthetic-overlap-client']);
    }

    /** @return iterable<string, array{string, string}> cadence and the start of the first retainer period after the agreement's first */
    public static function cadences(): iterable
    {
        yield 'monthly' => ['monthly', '2026-02-01'];
        yield 'quarterly' => ['quarterly', '2026-04-01'];
    }

    /** Two project agreements bill the same dates, each on its own project's work only. */
    #[DataProvider('cadences')]
    public function test_agreements_on_different_projects_each_bill_the_same_period_on_their_own_work(string $cadence, string $period): void
    {
        [$hosting, $care] = [$this->project('Hosting'), $this->project('Care')];
        $first = $this->agreement($cadence, $hosting);
        $second = $this->agreement($cadence, $care);
        $this->hours($hosting, '2026-01-20', 900);
        $this->hours($care, '2026-01-21', 300);

        $a = $this->generate($first, $period);
        $b = $this->generate($second, $period);

        $this->assertSame($a->service_period_start?->toDateString(), $b->service_period_start?->toDateString(), 'the same work period');
        $this->assertSame([[$hosting->id], 900], $this->allocated($a));
        $this->assertSame([[$care->id], 300], $this->allocated($b));
        // Regenerating either keeps to its own work.
        $this->assertSame([[$hosting->id], 900], $this->allocated(app(ClientInvoicingService::class)->regenerateDraftInvoice($a) ?? $a));
        $this->assertSame([[$care->id], 300], $this->allocated(app(ClientInvoicingService::class)->regenerateDraftInvoice($b) ?? $b));
        $this->assertSame(2, ClientInvoice::query()->where('workspace_id', $this->workspace->id)->where('invoice_kind', 'cadence_period')->count());
    }

    /** A company-wide agreement reaches the project's work: whichever bills first, the other is refused. */
    #[DataProvider('cadences')]
    public function test_a_company_wide_agreement_and_a_project_agreement_still_refuse_each_other(string $cadence, string $period): void
    {
        $project = $this->project('Hosting');
        $this->hours($project, '2026-01-20', 600);
        foreach ([[true, false], [false, true]] as $round => [$wideFirst]) {
            DB::table('client_invoice_line_time_entries')->delete();
            DB::table('client_invoice_lines')->delete();
            ClientInvoice::query()->delete();
            ClientAgreement::query()->delete();
            $wide = $this->agreement($cadence, null);
            $scoped = $this->agreement($cadence, $project);
            [$winner, $loser] = $wideFirst ? [$wide, $scoped] : [$scoped, $wide];

            $this->generate($winner, $period);
            $this->assertRefused($loser, $period, "round {$round}");
        }
    }

    /**
     * Another agreement on the same project reaches the same work: its invoice
     * over these dates refuses, though it is not this agreement's. (Two
     * agreements in force on one project at once are refused earlier, by the
     * handover guard; here the other one ended, and its invoice was dated
     * over this agreement's period.)
     */
    #[DataProvider('cadences')]
    public function test_another_agreement_on_the_same_project_still_refuses(string $cadence, string $period): void
    {
        $project = $this->project('Hosting');
        $agreement = $this->agreement($cadence, $project);
        $earlier = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'client_project_id' => $project->id,
            'title' => 'Synthetic earlier agreement', 'status' => 'terminated', 'starts_on' => '2025-01-01', 'ends_on' => '2025-12-31',
            'billing_cadence' => $cadence, 'currency' => 'USD',
        ]);
        [$from, $to] = $this->workPeriodOf($cadence, $period);
        $this->invoice($earlier, 'SYN-EARLIER', $from, $to);

        $this->assertRefused($agreement, $period);
    }

    /** History nobody can attribute fails closed: an invoice naming no agreement, or an agreement of another client. */
    #[DataProvider('cadences')]
    public function test_an_invoice_naming_no_agreement_or_another_clients_agreement_still_refuses(string $cadence, string $period): void
    {
        $project = $this->project('Hosting');
        $agreement = $this->agreement($cadence, $project);
        [$from, $to] = $this->workPeriodOf($cadence, $period);

        $unattributed = $this->invoice(null, 'SYN-NONE', $from, $to);
        $this->assertRefused($agreement, $period, 'no agreement');

        $unattributed->forceFill(['status' => 'void'])->save();
        $other = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic other client', 'slug' => 'synthetic-other-client']);
        $foreign = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $other->id, 'client_project_id' => ClientProject::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $other->id, 'name' => 'Theirs'])->id,
            'title' => 'Synthetic foreign agreement', 'status' => 'active', 'starts_on' => '2026-01-01', 'billing_cadence' => $cadence, 'currency' => 'USD',
        ]);
        $this->invoice($foreign, 'SYN-FOREIGN', $from, $to);
        $this->assertRefused($agreement, $period, 'another client\'s agreement');

        // And once nothing unattributable is live, the period bills.
        ClientInvoice::query()->where('invoice_number', 'SYN-FOREIGN')->update(['status' => 'void']);
        $this->assertSame('draft', $this->generate($agreement, $period)->status);
    }

    private function project(string $name): ClientProject
    {
        return ClientProject::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Synthetic '.$name]);
    }

    private function agreement(string $cadence, ?ClientProject $project): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'client_project_id' => $project?->id,
            'title' => 'Synthetic '.($project->name ?? 'company-wide').' agreement', 'status' => 'active', 'starts_on' => '2026-01-01',
            'billing_cadence' => $cadence, 'bill_overage_interim' => false, 'currency' => 'USD', 'hourly_rate_amount' => 10000,
            'retainer_minutes' => 600, 'retainer_amount' => 60000, 'rollover_months' => 0, 'catch_up_threshold_minutes' => 0,
        ]);
    }

    private function hours(ClientProject $project, string $day, int $minutes): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'client_project_id' => $project->id,
            'user_id' => $this->user->id, 'worked_on' => $day, 'minutes' => $minutes, 'description' => 'Synthetic work',
            'status' => 'approved', 'is_billable' => true, 'is_deferred' => false, 'billing_rate_amount' => 10000, 'currency' => 'USD',
        ])->refresh();
    }

    private function invoice(?ClientAgreement $agreement, string $number, ?string $from, ?string $to): ClientInvoice
    {
        return ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'client_agreement_id' => $agreement?->id,
            'invoice_number' => $number, 'invoice_kind' => 'cadence_period', 'status' => 'issued', 'currency' => 'USD',
            'subtotal_amount' => 100, 'tax_amount' => 0, 'total_amount' => 100, 'balance_amount' => 100,
            'service_period_start' => $from, 'service_period_end' => $to, 'hours_billed_at_rate' => 0,
        ]);
    }

    private function generate(ClientAgreement $agreement, string $period): ClientInvoice
    {
        return app(ClientInvoicingService::class)->generateCadencePeriod($this->company, $agreement->fresh() ?? $agreement, Carbon::parse($period));
    }

    private function assertRefused(ClientAgreement $agreement, string $period, string $case = ''): void
    {
        $before = ClientInvoice::query()->where('workspace_id', $this->workspace->id)->count();
        try {
            $this->generate($agreement, $period);
            $this->fail("The overlapping period was billed ({$case}).");
        } catch (RuntimeException $refused) {
            $this->assertStringContainsString('overlapping period', $refused->getMessage(), $case);
        }
        $this->assertSame($before, ClientInvoice::query()->where('workspace_id', $this->workspace->id)->count(), $case);
    }

    /** @return array{list<int>, int} the projects of the work the invoice allocates, and its minutes */
    private function allocated(ClientInvoice $invoice): array
    {
        $rows = DB::table('client_invoice_line_time_entries as allocation')
            ->join('client_invoice_lines as line', 'line.id', '=', 'allocation.client_invoice_line_id')
            ->join('client_time_entries as entry', 'entry.id', '=', 'allocation.client_time_entry_id')
            ->where('line.client_invoice_id', $invoice->id)
            ->distinct()
            ->get(['entry.id', 'entry.client_project_id', 'entry.minutes']);

        return [
            array_values(array_unique(array_map(static fn ($row): int => (int) $row->client_project_id, $rows->all()))),
            (int) $rows->sum('minutes'),
        ];
    }

    /** @return array{string, string} the work period an invoice selling this retainer period reconciles: the cycle before it */
    private function workPeriodOf(string $cadence, string $period): array
    {
        $months = $cadence === 'monthly' ? 1 : 3;
        $start = CarbonImmutable::parse($period)->subMonthsNoOverflow($months);

        return [$start->toDateString(), $start->addMonthsNoOverflow($months)->subDay()->toDateString()];
    }
}
