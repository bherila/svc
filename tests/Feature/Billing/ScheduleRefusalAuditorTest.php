<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientBillingSchedule;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Services\Billing\BillingScheduleService;
use App\Services\Billing\ScheduleRefusalAuditor;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceStatus;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The pre-deployment blast radius: which rows would halt a schedule, and why.
 *
 * Every case here is one branch of
 * `BillingPeriodCollisionResolver::classify()`, asserted from the outside as a
 * count. The audit exists because a refusal is not always cheap to recover
 * from - one naming a paid invoice halts a schedule on every run until a
 * financial correction is made - so an operator has to be able to size the
 * damage before the refusals are deployed rather than after.
 *
 * The clearing branches are asserted just as hard as the refusing ones. An
 * audit that over-reports is worse than useless as a deployment gate: it either
 * blocks a safe deploy or, once it has cried wolf, gets waved through with the
 * one real row buried in the noise.
 */
final class ScheduleRefusalAuditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_clean_database_would_refuse_nothing(): void
    {
        [$workspace, $company, $agreement, $schedule] = $this->scheduled('clean');
        $this->invoice($workspace, $company, [
            'client_agreement_id' => $agreement->id,
            'client_billing_schedule_id' => $schedule->id,
            'service_period_start' => '2026-08-01',
            'service_period_end' => '2026-08-31',
        ]);

        $counts = app(ScheduleRefusalAuditor::class)->count();

        $this->assertSame(0, $counts->wouldRefuse);
        $this->assertSame(0, $counts->schedulesHalted);
        $this->assertSame(1, $counts->schedules);
        $this->assertSame(1, $counts->candidates);
    }

    public function test_each_refusal_reason_is_counted_under_its_own_name(): void
    {
        [$workspace, $company, $agreement, $schedule] = $this->scheduled('reasons');

        // Names a schedule that does not exist.
        $this->invoice($workspace, $company, [
            'client_billing_schedule_id' => $schedule->id + 500,
            'service_period_start' => '2026-08-01', 'service_period_end' => '2026-08-31',
        ]);

        // Names an agreement that does not exist.
        $this->invoice($workspace, $company, [
            'client_agreement_id' => $agreement->id + 500,
            'service_period_start' => '2026-08-01', 'service_period_end' => '2026-08-31',
        ]);

        // Both resolve, and disagree with each other.
        $other = $this->agreement($workspace, $company, 'Second');
        $this->invoice($workspace, $company, [
            'client_billing_schedule_id' => $schedule->id, 'client_agreement_id' => $other->id,
            'service_period_start' => '2026-08-01', 'service_period_end' => '2026-08-31',
        ]);

        // Owned, and carrying a status this code cannot read.
        $this->invoice($workspace, $company, [
            'client_billing_schedule_id' => $schedule->id, 'client_agreement_id' => $agreement->id,
            'status' => 'awaiting_dispute_resolution',
            'service_period_start' => '2026-09-01', 'service_period_end' => '2026-09-30',
        ]);

        // Owned, and missing a boundary, so no date comparison can place it.
        $this->invoice($workspace, $company, [
            'client_billing_schedule_id' => $schedule->id, 'client_agreement_id' => $agreement->id,
            'service_period_start' => '2026-08-01', 'service_period_end' => null,
        ]);

        // Attributed to nobody, in a company where a second agreement could
        // plausibly own it.
        $this->invoice($workspace, $company, [
            'service_period_start' => '2026-08-01', 'service_period_end' => '2026-08-31',
        ]);

        $counts = app(ScheduleRefusalAuditor::class)->count();

        $this->assertSame(1, $counts->danglingScheduleLink);
        $this->assertSame(1, $counts->danglingAgreementLink);
        $this->assertSame(1, $counts->contradictoryLineage);
        $this->assertSame(1, $counts->unknownStatus);
        $this->assertSame(1, $counts->incompletePeriodOnAnOwnedRow);
        $this->assertSame(1, $counts->unattributedAndContested);

        // The reasons partition the total rather than overlapping it.
        $this->assertSame(6, $counts->wouldRefuse);
        $this->assertSame(1, $counts->schedulesHalted);
    }

    /**
     * A row whose lineage dangles *and* whose period is incomplete is one row.
     *
     * Counting the reasons independently would report it twice and make the
     * breakdown sum to more than the total it explains - which is exactly the
     * kind of number that gets a deployment gate ignored.
     */
    public function test_a_row_matching_two_reasons_is_attributed_to_the_first(): void
    {
        [$workspace, $company, , $schedule] = $this->scheduled('overlapping-reasons');

        $this->invoice($workspace, $company, [
            'client_billing_schedule_id' => $schedule->id + 500,
            'service_period_start' => null, 'service_period_end' => null,
        ]);

        $counts = app(ScheduleRefusalAuditor::class)->count();

        $this->assertSame(1, $counts->danglingScheduleLink);
        $this->assertSame(0, $counts->incompletePeriodOnAnOwnedRow);
        $this->assertSame(1, $counts->wouldRefuse);
    }

    /**
     * The branches that clear, each of which the resolver reaches before any
     * refusal - so an audit that counted them would over-report every one.
     */
    public function test_the_shapes_the_resolver_clears_are_not_counted(): void
    {
        [$workspace, $company, $agreement, $schedule] = $this->scheduled('cleared');

        // A known void with dangling lineage: cleared before ownership is
        // resolved at all, because voiding is the documented way out and a
        // refusal on a voided row would have none.
        $this->invoice($workspace, $company, [
            'client_billing_schedule_id' => $schedule->id + 500,
            'status' => InvoiceStatus::Void->value,
            'service_period_start' => '2026-08-01', 'service_period_end' => '2026-08-31',
        ]);

        // An unlinked ad-hoc invoice: exempt before the lineage refusals, so a
        // dangling agreement on one cannot halt anything.
        $this->invoice($workspace, $company, [
            'client_agreement_id' => $agreement->id + 500,
            'invoice_kind' => InvoiceKind::AdHoc->value,
            'service_period_start' => '2026-08-01', 'service_period_end' => '2026-08-31',
        ]);

        // No dates and no lineage is no evidence at all.
        $this->invoice($workspace, $company, [
            'service_period_start' => null, 'service_period_end' => null,
        ]);

        $counts = app(ScheduleRefusalAuditor::class)->count();

        $this->assertSame(0, $counts->wouldRefuse);
        $this->assertSame(0, $counts->schedulesHalted);
    }

    /**
     * A company nobody bills on a cadence cannot halt a schedule, however
     * malformed its invoices are. `possiblyOverlapping()` matches the
     * schedule's workspace *and* client company, so these rows are never
     * candidates - and counting them would restate the invoice table.
     */
    public function test_invoices_for_a_company_with_no_schedule_are_not_candidates(): void
    {
        $workspace = $this->workspace('unscheduled');
        $company = $this->company($workspace, 'unscheduled');
        $this->invoice($workspace, $company, [
            'client_billing_schedule_id' => 9_999,
            'service_period_start' => null, 'service_period_end' => null,
        ]);

        $counts = app(ScheduleRefusalAuditor::class)->count();

        $this->assertSame(1, $counts->invoices);
        $this->assertSame(0, $counts->candidates);
        $this->assertSame(0, $counts->wouldRefuse);
    }

    /**
     * An unattributed invoice refuses only when somebody else could claim it.
     * With one agreement and one schedule there is no rival, so the schedule
     * treats the row as its own and the run continues.
     */
    public function test_an_unattributed_invoice_is_not_counted_without_a_rival_owner(): void
    {
        [$workspace, $company] = $this->scheduled('sole-owner');

        $this->invoice($workspace, $company, [
            'service_period_start' => '2026-08-01', 'service_period_end' => '2026-08-31',
        ]);

        $counts = app(ScheduleRefusalAuditor::class)->count();

        $this->assertSame(0, $counts->unattributedAndContested);
        $this->assertSame(0, $counts->wouldRefuse);
    }

    /**
     * Ten broken rows in one company halt one schedule, not ten. Rows are not
     * the unit of damage - a halted schedule is, and that is the number an
     * operator decides on.
     */
    public function test_the_halt_count_is_schedules_and_not_rows(): void
    {
        [$workspace, $company, , $schedule] = $this->scheduled('blast-radius');
        $this->scheduled('untouched');

        // Distinct periods only because
        // `billing_schedule_service_period_unique` will not take three
        // identical tuples; the audit is period-agnostic.
        foreach (['2026-08', '2026-09', '2026-10'] as $month) {
            $this->invoice($workspace, $company, [
                'client_billing_schedule_id' => $schedule->id + 500,
                'service_period_start' => $month.'-01', 'service_period_end' => $month.'-28',
            ]);
        }

        $counts = app(ScheduleRefusalAuditor::class)->count();

        $this->assertSame(3, $counts->wouldRefuse);
        $this->assertSame(1, $counts->schedulesHalted);
        $this->assertSame(2, $counts->schedules);
    }

    /**
     * The tenancy boundary, asserted the way every other read surface asserts
     * it: with a neighbour that must not appear, rather than a single-tenant
     * happy path a leaking scope would also pass.
     */
    public function test_a_scoped_audit_sees_only_its_own_workspace(): void
    {
        [$mine, $company, , $schedule] = $this->scheduled('mine');
        [$theirWorkspace, $theirCompany, , $theirSchedule] = $this->scheduled('theirs');

        $this->invoice($theirWorkspace, $theirCompany, [
            'client_billing_schedule_id' => $theirSchedule->id + 500,
            'service_period_start' => '2026-08-01', 'service_period_end' => '2026-08-31',
        ]);

        $counts = app(ScheduleRefusalAuditor::class)->count($mine);

        $this->assertSame(0, $counts->wouldRefuse);
        $this->assertSame(0, $counts->schedulesHalted);
        $this->assertSame(1, $counts->schedules);
    }

    /**
     * A schedule in another *workspace* that happens to share a company id must
     * not resolve a link. The lineage lookups match workspace and company
     * together for the same reason the resolver does.
     */
    public function test_a_schedule_in_another_workspace_does_not_resolve_a_link(): void
    {
        [$workspace, $company] = $this->scheduled('near');
        [, , , $foreign] = $this->scheduled('far');

        $this->invoice($workspace, $company, [
            'client_billing_schedule_id' => $foreign->id,
            'service_period_start' => '2026-08-01', 'service_period_end' => '2026-08-31',
        ]);

        $counts = app(ScheduleRefusalAuditor::class)->count($workspace);

        $this->assertSame(1, $counts->danglingScheduleLink);
    }

    /**
     * What the audit predicts is what the schedule actually does.
     *
     * The two derivations are necessarily separate - one is SQL over the whole
     * table, the other is PHP over the candidates for one period - so they can
     * drift, and a deployment gate that has drifted is worse than none. Each
     * branch is pinned individually in `BillingWorkflowTest`; this ties the
     * prediction to the behaviour so a change to one that is not made to the
     * other fails here.
     *
     * @param  array<string, mixed>  $row
     */
    #[DataProvider('refusingRows')]
    public function test_a_row_the_audit_counts_actually_halts_the_schedule(string $reason, array $row): void
    {
        [$workspace, $company, $agreement, $schedule] = $this->scheduled('conformance');
        $second = $this->agreement($workspace, $company, 'Second');

        $this->invoice($workspace, $company, array_map(
            static fn (mixed $value): mixed => match ($value) {
                ':schedule' => $schedule->id,
                ':missing-schedule' => $schedule->id + 500,
                ':agreement' => $agreement->id,
                ':missing-agreement' => $agreement->id + 500,
                ':other-agreement' => $second->id,
                default => $value,
            },
            $row,
        ));

        $counts = app(ScheduleRefusalAuditor::class)->count($workspace);
        $this->assertSame(1, $counts->wouldRefuse, "the audit did not count the {$reason} row");
        $this->assertSame(1, $counts->schedulesHalted);

        $this->expectException(DomainException::class);
        app(BillingScheduleService::class)->generateDue($schedule, CarbonImmutable::parse('2026-08-15'));
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function refusingRows(): array
    {
        $august = ['service_period_start' => '2026-08-01', 'service_period_end' => '2026-08-31'];

        return [
            'dangling schedule link' => ['dangling schedule link', ['client_billing_schedule_id' => ':missing-schedule'] + $august],
            'dangling agreement link' => ['dangling agreement link', ['client_agreement_id' => ':missing-agreement'] + $august],
            'contradictory lineage' => ['contradictory lineage', [
                'client_billing_schedule_id' => ':schedule', 'client_agreement_id' => ':other-agreement',
            ] + $august],
            'unknown status' => ['unknown status', [
                'client_billing_schedule_id' => ':schedule', 'client_agreement_id' => ':agreement',
                'status' => 'awaiting_dispute_resolution',
            ] + $august],
            'incomplete period on an owned row' => ['incomplete period on an owned row', [
                'client_billing_schedule_id' => ':schedule', 'client_agreement_id' => ':agreement',
                'service_period_start' => '2026-08-01', 'service_period_end' => null,
            ]],
            'unattributed and contested' => ['unattributed and contested', $august],
        ];
    }

    /**
     * @return array{0: Workspace, 1: ClientCompany, 2: ClientAgreement, 3: ClientBillingSchedule}
     */
    private function scheduled(string $slug): array
    {
        $workspace = $this->workspace($slug);
        $company = $this->company($workspace, $slug);
        $agreement = $this->agreement($workspace, $company, 'Retainer');
        $schedule = ClientBillingSchedule::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_agreement_id' => $agreement->id,
            'cadence' => 'monthly',
            'next_run_on' => '2026-08-01',
            'due_days' => 14,
            'currency' => 'USD',
            'line_template' => [['description' => 'Retainer', 'quantity' => 1, 'unit_amount' => 1000]],
        ]);

        return [$workspace, $company, $agreement, $schedule];
    }

    private function workspace(string $slug): Workspace
    {
        return Workspace::query()->create(['name' => ucfirst($slug).' Workspace', 'slug' => $slug.'-workspace']);
    }

    private function company(Workspace $workspace, string $slug): ClientCompany
    {
        return ClientCompany::query()->create([
            'workspace_id' => $workspace->id, 'name' => ucfirst($slug).' Client', 'slug' => $slug.'-client',
        ]);
    }

    private function agreement(Workspace $workspace, ClientCompany $company, string $title): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => $title,
            'status' => 'active', 'currency' => 'USD', 'starts_on' => '2026-01-01', 'retainer_minutes' => 600,
        ]);
    }

    /**
     * `forceFill` for the overrides, because this audit's whole subject is
     * columns a normal create would not let a fixture set - a dangling lineage
     * id, a null period, a status no enum case matches.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function invoice(Workspace $workspace, ClientCompany $company, array $overrides = []): ClientInvoice
    {
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'invoice_number' => strtoupper($workspace->slug).'-'.uniqid(),
            'status' => 'draft',
            'currency' => 'USD',
            'subtotal_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
        ]);

        $invoice->forceFill($overrides)->save();

        return $invoice;
    }
}
