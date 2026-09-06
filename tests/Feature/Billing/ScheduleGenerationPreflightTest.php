<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientBillingSchedule;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Services\Billing\BillingScheduleService;
use App\Services\Billing\ScheduleGenerationPreflight;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceStatus;
use App\Support\Billing\PeriodRefusalReason;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The pre-deployment gate: what would stop, and - just as hard - what would not.
 *
 * The predecessor of this class classified rows with its own SQL and was wrong
 * in both directions, because half the resolver's rules are about a
 * `(schedule, period, invoice)` triple and no row-at-a-time query can answer a
 * question with a period in it. This runs the real resolver over the real due
 * periods, so the tests below are about whether the *prediction* matches the
 * *run* - and every case asserts both.
 *
 * `assertPredictionMatchesTheRun()` is the whole point of the file: it runs the
 * preflight and then runs `generateDue()`, and fails if they disagree in either
 * direction. A gate that only ever proves "counted → halted" can still be
 * uselessly green, and that is exactly how the previous version shipped a bug.
 */
final class ScheduleGenerationPreflightTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    /**
     * Every shape that halts a schedule, and the reason it is reported under.
     *
     * @param  array<string, mixed>  $row
     */
    #[DataProvider('haltingRows')]
    public function test_a_halting_shape_is_predicted_and_actually_halts(array $row, ?PeriodRefusalReason $reason): void
    {
        [$workspace, $company, $agreement, $schedule] = $this->scheduled('halting');
        $second = $this->agreement($workspace, $company, 'Second');

        $this->invoice($workspace, $company, $this->resolve($row, $schedule, $agreement, $second));

        $report = app(ScheduleGenerationPreflight::class)->run($workspace, $this->through());

        $this->assertSame(1, $report->wouldHalt);
        if ($reason instanceof PeriodRefusalReason) {
            $this->assertSame(1, $report->haltedByARefusal);
            $this->assertSame(1, $report->refusalsByReason[$reason->value]);
        } else {
            $this->assertSame(1, $report->haltedByAPendingDraft);
            $this->assertSame(0, $report->haltedByARefusal);
        }

        $this->assertPredictionMatchesTheRun($workspace, $schedule);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: ?PeriodRefusalReason}>
     */
    public static function haltingRows(): array
    {
        $august = ['service_period_start' => '2026-08-01', 'service_period_end' => '2026-08-31'];

        return [
            'dangling schedule link' => [
                ['client_billing_schedule_id' => ':missing-schedule'] + $august,
                PeriodRefusalReason::DanglingSchedule,
            ],
            'dangling agreement link' => [
                ['client_agreement_id' => ':missing-agreement'] + $august,
                PeriodRefusalReason::DanglingAgreement,
            ],
            'contradictory lineage' => [
                ['client_billing_schedule_id' => ':schedule', 'client_agreement_id' => ':other-agreement'] + $august,
                PeriodRefusalReason::ContradictoryLineage,
            ],
            'unattributed with a rival owner' => [
                $august,
                PeriodRefusalReason::Unattributed,
            ],
            'unknown status on an owned row' => [
                [
                    'client_billing_schedule_id' => ':schedule', 'client_agreement_id' => ':agreement',
                    'status' => 'awaiting_dispute_resolution',
                ] + $august,
                PeriodRefusalReason::UnknownStatus,
            ],
            'incomplete period on an owned row' => [
                [
                    'client_billing_schedule_id' => ':schedule', 'client_agreement_id' => ':agreement',
                    'service_period_start' => '2026-08-01', 'service_period_end' => null,
                ],
                PeriodRefusalReason::IncompletePeriod,
            ],
            'partial overlap' => [
                [
                    'client_billing_schedule_id' => ':schedule', 'client_agreement_id' => ':agreement',
                    'status' => InvoiceStatus::Issued->value,
                    'service_period_start' => '2026-07-01', 'service_period_end' => '2026-08-15',
                ],
                PeriodRefusalReason::PartialOverlap,
            ],
            'pending draft claiming the period' => [
                ['client_agreement_id' => ':agreement', 'invoice_kind' => InvoiceKind::CadencePeriod->value] + $august,
                null,
            ],
        ];
    }

    /**
     * A partial overlap is the case the row-level predecessor could not see at
     * all, and it admitted as much in its own green message.
     *
     * Whether an invoice overlaps *without matching* is a fact about a
     * `(period, invoice)` pair, so the old audit reported zero for it and told
     * operators its zero was not a guarantee. Asserted on its own rather than
     * only through the provider above, because it is the finding that forced
     * this class to be rewritten.
     */
    public function test_a_partial_overlap_is_predicted_which_the_row_level_audit_could_not_do(): void
    {
        [$workspace, $company, $agreement, $schedule] = $this->scheduled('partial');

        $this->invoice($workspace, $company, [
            'client_billing_schedule_id' => $schedule->id,
            'client_agreement_id' => $agreement->id,
            'status' => InvoiceStatus::Issued->value,
            'service_period_start' => '2026-07-15',
            'service_period_end' => '2026-08-10',
        ]);

        $report = app(ScheduleGenerationPreflight::class)->run($workspace, $this->through());

        $this->assertSame(1, $report->refusalsByReason[PeriodRefusalReason::PartialOverlap->value]);
        $this->assertPredictionMatchesTheRun($workspace, $schedule);
    }

    /**
     * The inverse direction, which the predecessor never asserted and got wrong.
     *
     * The resolver clears a known void **only when it is not an exact match**.
     * An inexact void charged nobody and cannot stand in for this period, so it
     * is dropped before ownership is even resolved. An *exact* void is a
     * candidate to be this period's deliberate waiver, so it goes on to the
     * lineage questions - and a waiver whose lineage does not resolve cannot be
     * shown to be this schedule's waiver, so it refuses.
     *
     * The predecessor cleared every void unconditionally and so reported zero
     * for the exact case, which halts production. The paired fixtures are the
     * point: same row, one day apart, opposite answers, both checked against
     * what the run actually does.
     */
    public function test_an_exact_void_with_dangling_lineage_refuses_while_an_inexact_one_clears(): void
    {
        [$exactWorkspace, $exactCompany, , $exactSchedule] = $this->scheduled('exact-void');
        $this->invoice($exactWorkspace, $exactCompany, [
            'client_billing_schedule_id' => $exactSchedule->id + 500,
            'status' => InvoiceStatus::Void->value,
            'service_period_start' => '2026-08-01', 'service_period_end' => '2026-08-31',
        ]);

        $exact = app(ScheduleGenerationPreflight::class)->run($exactWorkspace, $this->through());
        $this->assertSame(1, $exact->wouldHalt, 'an exact void could be this period\'s waiver, so whose it is matters');
        $this->assertSame(1, $exact->refusalsByReason[PeriodRefusalReason::DanglingSchedule->value]);
        $this->assertPredictionMatchesTheRun($exactWorkspace, $exactSchedule);

        [$inexactWorkspace, $inexactCompany, , $inexactSchedule] = $this->scheduled('inexact-void');
        $this->invoice($inexactWorkspace, $inexactCompany, [
            'client_billing_schedule_id' => $inexactSchedule->id + 500,
            'status' => InvoiceStatus::Void->value,
            'service_period_start' => '2026-08-01', 'service_period_end' => '2026-08-30',
        ]);

        $inexact = app(ScheduleGenerationPreflight::class)->run($inexactWorkspace, $this->through());
        $this->assertSame(0, $inexact->wouldHalt, 'an inexact void charged nobody and is cleared before lineage');
        $this->assertPredictionMatchesTheRun($inexactWorkspace, $inexactSchedule);
    }

    /**
     * A sole owner implicitly claims a row naming neither owner, and `mine()`
     * then refuses it for an unknown status or an incomplete period.
     *
     * The predecessor returned "no refusal" for exactly this shape, because it
     * treated "names neither owner and has no rival" as a clear rather than as
     * an implicit claim - so a false green on two of the reason families it
     * claimed to measure.
     *
     * @param  array<string, mixed>  $row
     */
    #[DataProvider('soleOwnerRows')]
    public function test_a_sole_owner_claims_an_unattributed_row_and_can_refuse_it(array $row, PeriodRefusalReason $reason): void
    {
        [$workspace, $company, , $schedule] = $this->scheduled('sole-owner');

        $this->invoice($workspace, $company, $row);

        $report = app(ScheduleGenerationPreflight::class)->run($workspace, $this->through());

        $this->assertSame(1, $report->refusalsByReason[$reason->value]);
        $this->assertPredictionMatchesTheRun($workspace, $schedule);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: PeriodRefusalReason}>
     */
    public static function soleOwnerRows(): array
    {
        return [
            'unknown status' => [
                [
                    'status' => 'awaiting_dispute_resolution',
                    'service_period_start' => '2026-08-01', 'service_period_end' => '2026-08-31',
                ],
                PeriodRefusalReason::UnknownStatus,
            ],
            'incomplete period' => [
                [
                    'status' => InvoiceStatus::Issued->value,
                    'service_period_start' => '2026-08-01', 'service_period_end' => null,
                ],
                PeriodRefusalReason::IncompletePeriod,
            ],
        ];
    }

    /**
     * An invoice naming a real agreement that no schedule bills is somebody
     * else's, not an ambiguous row.
     *
     * The predecessor reported it as `unattributed_and_contested` and exited
     * non-zero on perfectly ordinary billing history - `ClientInvoicingService`
     * creates cadence invoices with an agreement and no schedule, so an
     * agreement between schedules produces exactly this. A gate that fails on
     * valid data gets waved through, which is worse than no gate.
     */
    public function test_an_invoice_on_an_agreement_no_schedule_bills_is_not_contested(): void
    {
        [$workspace, $company, , $schedule] = $this->scheduled('other-agreement');
        $unscheduled = $this->agreement($workspace, $company, 'Unscheduled');

        $this->invoice($workspace, $company, [
            'client_agreement_id' => $unscheduled->id,
            'invoice_kind' => InvoiceKind::CadencePeriod->value,
            'service_period_start' => '2026-08-01', 'service_period_end' => '2026-08-31',
        ]);

        $report = app(ScheduleGenerationPreflight::class)->run($workspace, $this->through());

        $this->assertSame(0, $report->wouldHalt);
        $this->assertPredictionMatchesTheRun($workspace, $schedule);
    }

    /**
     * An inactive schedule contributes nothing, because `generateDue()` returns
     * before consulting the resolver at all.
     *
     * The predecessor counted every schedule on an affected company, active or
     * not, so a broken invoice owned by a dormant schedule reported two halts
     * where the real answer was zero.
     */
    public function test_an_inactive_schedule_is_neither_counted_nor_halted(): void
    {
        [$workspace, $company, $agreement, $schedule] = $this->scheduled('inactive');
        $schedule->forceFill(['is_active' => false])->save();

        $this->invoice($workspace, $company, [
            'client_billing_schedule_id' => $schedule->id + 500,
            'client_agreement_id' => $agreement->id,
            'service_period_start' => '2026-08-01', 'service_period_end' => '2026-08-31',
        ]);

        $report = app(ScheduleGenerationPreflight::class)->run($workspace, $this->through());

        $this->assertSame(0, $report->schedules);
        $this->assertSame(0, $report->wouldHalt);

        // And the run agrees: an inactive schedule creates nothing and throws
        // nothing, whatever its client's invoices look like.
        $this->assertSame([], app(BillingScheduleService::class)->generateDue($schedule, $this->through()));
    }

    /**
     * Only one halt per schedule, and it is the first period that stops it.
     *
     * `generateDue()` throws on the first undecidable period, so counting the
     * later ones would report damage that never happens - and counting rows
     * rather than schedules would report ten halts for one stopped schedule.
     */
    public function test_a_schedule_halts_once_at_its_first_undecidable_period(): void
    {
        [$workspace, $company, $agreement, $schedule] = $this->scheduled('first-stop');

        foreach (['2026-08' => 'dangling', '2026-09' => 'dangling', '2026-10' => 'dangling'] as $month => $ignored) {
            $this->invoice($workspace, $company, [
                'client_billing_schedule_id' => $schedule->id + 500,
                'service_period_start' => $month.'-01', 'service_period_end' => $month.'-28',
            ]);
        }

        $report = app(ScheduleGenerationPreflight::class)->run($workspace, CarbonImmutable::parse('2026-12-15'));

        $this->assertSame(1, $report->wouldHalt);
        $this->assertSame(1, $report->refusalsByReason[PeriodRefusalReason::DanglingSchedule->value]);
        // August only: the walk stops where the run would stop.
        $this->assertSame(1, $report->periodsClassified);
    }

    /**
     * A clean schedule classifies every period it is due and halts on none.
     */
    public function test_a_clean_schedule_classifies_its_due_periods_and_halts_on_none(): void
    {
        [$workspace, , , $schedule] = $this->scheduled('clean');

        $report = app(ScheduleGenerationPreflight::class)->run($workspace, CarbonImmutable::parse('2026-10-15'));

        $this->assertSame(1, $report->schedules);
        $this->assertSame(1, $report->schedulesDue);
        $this->assertSame(3, $report->periodsClassified, 'August, September and October are due');
        $this->assertSame(0, $report->wouldHalt);
        $this->assertPredictionMatchesTheRun($workspace, $schedule);
    }

    /**
     * A schedule with nothing due is reported as not due, not as clean. No
     * period of it was examined, so there is nothing to be clean about.
     */
    public function test_a_schedule_with_nothing_due_is_counted_but_not_classified(): void
    {
        [$workspace] = $this->scheduled('not-due');

        $report = app(ScheduleGenerationPreflight::class)->run($workspace, CarbonImmutable::parse('2026-07-01'));

        $this->assertSame(1, $report->schedules);
        $this->assertSame(0, $report->schedulesDue);
        $this->assertSame(0, $report->periodsClassified);
        $this->assertSame(0, $report->wouldHalt);
    }

    /**
     * The tenancy boundary, asserted with a neighbour that must not appear
     * rather than a single-tenant happy path a leaking scope would also pass.
     */
    public function test_a_scoped_preflight_sees_only_its_own_workspace(): void
    {
        [$mine] = $this->scheduled('mine');
        [$theirWorkspace, $theirCompany, , $theirSchedule] = $this->scheduled('theirs');

        $this->invoice($theirWorkspace, $theirCompany, [
            'client_billing_schedule_id' => $theirSchedule->id + 500,
            'service_period_start' => '2026-08-01', 'service_period_end' => '2026-08-31',
        ]);

        $report = app(ScheduleGenerationPreflight::class)->run($mine, $this->through());

        $this->assertSame(1, $report->schedules);
        $this->assertSame(0, $report->wouldHalt);
    }

    /**
     * The prediction and the run agree, in both directions.
     *
     * Asserted for every fixture in this file. "The audit counted it and the
     * run halted" is only half a gate; the half that matters for a green
     * deployment is "the audit cleared it and the run did not halt", and that
     * is the half the row-level predecessor never checked and got wrong.
     */
    private function assertPredictionMatchesTheRun(Workspace $workspace, ClientBillingSchedule $schedule): void
    {
        $predictedToHalt = app(ScheduleGenerationPreflight::class)
            ->run($workspace, $this->through())->wouldHalt > 0;

        $halted = false;
        $thrown = '';
        try {
            app(BillingScheduleService::class)->generateDue($schedule->fresh(), $this->through());
        } catch (DomainException $halt) {
            $halted = true;
            $thrown = $halt->getMessage();
        }

        // The thrown message is carried into the failure deliberately. A
        // disagreement between the two is a drift bug, and the first question
        // is always "on what?" - which the run answers and a boolean does not.
        $this->assertSame(
            $predictedToHalt,
            $halted,
            $predictedToHalt
                ? 'the preflight predicted a halt the run did not produce'
                : 'the run halted on data the preflight reported as clean: '.$thrown,
        );
    }

    private function through(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-08-15');
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function resolve(
        array $row,
        ClientBillingSchedule $schedule,
        ClientAgreement $agreement,
        ClientAgreement $other,
    ): array {
        return array_map(
            static fn (mixed $value): mixed => match ($value) {
                ':schedule' => $schedule->id,
                ':missing-schedule' => $schedule->id + 500,
                ':agreement' => $agreement->id,
                ':missing-agreement' => $agreement->id + 500,
                ':other-agreement' => $other->id,
                default => $value,
            },
            $row,
        );
    }

    /**
     * @return array{0: Workspace, 1: ClientCompany, 2: ClientAgreement, 3: ClientBillingSchedule}
     */
    private function scheduled(string $slug): array
    {
        $workspace = Workspace::query()->create(['name' => ucfirst($slug).' Workspace', 'slug' => $slug.'-workspace']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id, 'name' => ucfirst($slug).' Client', 'slug' => $slug.'-client',
        ]);
        $agreement = $this->agreement($workspace, $company, 'Retainer');
        $schedule = ClientBillingSchedule::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id,
            'client_agreement_id' => $agreement->id, 'cadence' => 'monthly', 'next_run_on' => '2026-08-01',
            'due_days' => 14, 'currency' => 'USD',
            'line_template' => [[
                'type' => 'service', 'description' => 'Retainer', 'quantity' => '1',
                'unit_amount' => 100000, 'tax_amount' => 0, 'sort_order' => 1,
            ]],
        ]);

        return [$workspace, $company, $agreement, $schedule];
    }

    private function agreement(Workspace $workspace, ClientCompany $company, string $title): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => $title,
            'status' => 'active', 'currency' => 'USD', 'starts_on' => '2026-01-01', 'retainer_minutes' => 600,
        ]);
    }

    /**
     * `forceFill` for the overrides, because the shapes this classifies are
     * columns a normal create would not let a fixture set - a dangling lineage
     * id, a null boundary, a status no enum case matches.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function invoice(Workspace $workspace, ClientCompany $company, array $overrides = []): ClientInvoice
    {
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id,
            'invoice_number' => strtoupper($workspace->slug).'-'.uniqid(), 'status' => 'draft', 'currency' => 'USD',
            'subtotal_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0,
        ]);

        $invoice->forceFill($overrides)->save();

        return $invoice;
    }
}
