<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientBillingSchedule;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Support\Billing\PeriodRefusalReason;
use App\Support\Billing\ScheduleDefect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The deployment gate: the exit code, the shape, and what it never prints.
 *
 * `ScheduleGenerationPreflightTest` covers which shapes halt and why. This
 * covers the three things a pipeline and an operator depend on and the service
 * cannot provide on its own: a non-zero exit when something would stop, a JSON
 * contract stable enough to diff between runs, and output containing no
 * identifier - so a run against real client billing records is safe to paste
 * into a public issue.
 */
final class PreflightScheduleGenerationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_clean_database_exits_zero_and_says_what_it_did_not_promise(): void
    {
        $this->scheduledWorkspace('clean');

        $this->assertSame(0, Artisan::call('svc:billing:preflight-schedule-generation', ['--through' => '2026-08-15']));
        $output = Artisan::output();

        $this->assertStringContainsString('no active schedule would halt', $output);

        // A green line has to be honest about its own scope, or it gets read as
        // a guarantee. This takes no locks, so the data can change before the
        // run; and a schedule with nothing due was never examined.
        $this->assertStringContainsString('takes no locks', $output);
        $this->assertStringContainsString('nothing due was not examined', $output);
    }

    public function test_a_halting_schedule_exits_non_zero_so_a_pipeline_can_gate_on_it(): void
    {
        [$workspace, $company, , $schedule] = $this->scheduledWorkspace('halted');
        $this->danglingInvoice($workspace, $company, $schedule);

        $this->assertSame(1, Artisan::call('svc:billing:preflight-schedule-generation', ['--through' => '2026-08-15']));
        $output = Artisan::output();

        $this->assertStringContainsString('would stop rather than bill a period now due', $output);
        $this->assertStringContainsString(PeriodRefusalReason::DanglingSchedule->summary(), $output);
    }

    public function test_the_json_shape_is_the_documented_contract(): void
    {
        [$workspace, $company, , $schedule] = $this->scheduledWorkspace('json');
        $this->danglingInvoice($workspace, $company, $schedule);

        $this->assertSame(1, Artisan::call('svc:billing:preflight-schedule-generation', [
            '--through' => '2026-08-15', '--format' => 'json',
        ]));
        $decoded = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame([
            'schedules' => 1,
            'schedules_due' => 1,
            'periods_classified' => 1,
            'complete' => true,
            'would_halt' => 1,
            'halted_by_a_refusal' => 1,
            'halted_by_a_pending_draft' => 0,
            'halted_by_a_schedule_defect' => 0,
            'schedules_truncated' => 0,
            'refusals_by_reason' => [
                'dangling_schedule_link' => 1,
                'dangling_agreement_link' => 0,
                'contradictory_lineage' => 0,
                'unattributed_and_contested' => 0,
                'unknown_status' => 0,
                'incomplete_period' => 0,
                'partial_overlap' => 0,
                'conflicting_exact_claims' => 0,
            ],
            'defects_by_kind' => [
                'unreadable_cadence' => 0,
                'unreadable_line_template' => 0,
            ],
        ], $decoded['summary']);
    }

    /**
     * A run that did not examine everything exits non-zero, in both formats.
     *
     * The failure this pins: an earlier revision made the pass hinge on
     * `would_halt` alone, so a schedule whose backlog outran the cap - whose
     * unexamined periods are precisely the ones nobody classified - printed the
     * green line and exited zero. A gate that certifies what it declined to
     * inspect is worse than no gate, because the pipeline stops asking.
     */
    public function test_a_truncated_run_fails_rather_than_certifying_what_it_did_not_examine(): void
    {
        $this->scheduledWorkspace('truncated');

        $exit = Artisan::call('svc:billing:preflight-schedule-generation', [
            '--through' => '2026-10-15', '--periods-per-schedule' => '2',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('inconclusive rather than clean', $output);
        $this->assertStringNotContainsString('no active schedule would halt', $output);
    }

    /**
     * And the JSON lane is not the lenient one. A pipeline is far likelier to
     * test the exit code than to parse `complete` out of the payload, so the
     * two have to agree.
     */
    public function test_a_truncated_json_run_reports_incomplete_and_exits_non_zero(): void
    {
        $this->scheduledWorkspace('truncated-json');

        $exit = Artisan::call('svc:billing:preflight-schedule-generation', [
            '--through' => '2026-10-15', '--periods-per-schedule' => '2', '--format' => 'json',
        ]);
        $decoded = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertFalse($decoded['summary']['complete']);
        $this->assertSame(0, $decoded['summary']['would_halt'], 'nothing halted among the periods it did look at');
        $this->assertSame(1, $decoded['summary']['schedules_truncated']);
    }

    /**
     * Raising the cap is how an operator turns "I do not know" into an answer,
     * so the option has to actually finish the job.
     */
    public function test_a_larger_cap_completes_a_backlog_that_truncated_at_the_smaller_one(): void
    {
        $this->scheduledWorkspace('uncapped');

        $exit = Artisan::call('svc:billing:preflight-schedule-generation', [
            '--through' => '2026-10-15', '--periods-per-schedule' => '12',
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('no active schedule would halt', Artisan::output());
    }

    public function test_an_unusable_period_cap_is_rejected_rather_than_guessed(): void
    {
        foreach (['0', '-3', 'lots', '2.5'] as $cap) {
            $this->assertSame(2, Artisan::call('svc:billing:preflight-schedule-generation', [
                '--periods-per-schedule' => $cap,
            ]), $cap.' is not a number of periods');
        }
    }

    /**
     * Every reason is present with a zero, so two runs can be diffed without a
     * consumer having to decide whether a missing key means "none" or means the
     * vocabulary changed underneath it.
     */
    public function test_every_reason_is_reported_even_when_it_did_not_fire(): void
    {
        $this->scheduledWorkspace('vocabulary');

        Artisan::call('svc:billing:preflight-schedule-generation', ['--through' => '2026-08-15', '--format' => 'json']);
        $decoded = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

        foreach (PeriodRefusalReason::cases() as $reason) {
            $this->assertArrayHasKey($reason->value, $decoded['summary']['refusals_by_reason']);
        }

        foreach (ScheduleDefect::cases() as $defect) {
            $this->assertArrayHasKey($defect->value, $decoded['summary']['defects_by_kind']);
        }
    }

    /**
     * Counts only. Enforced by the shape of `ScheduleGenerationPreflightReport`,
     * and asserted here anyway, because the whole point of running this against
     * production is being able to paste the result somewhere public.
     */
    public function test_the_report_names_no_row_company_or_workspace(): void
    {
        [$workspace, $company, $agreement, $schedule] = $this->scheduledWorkspace('secret');
        $invoice = $this->danglingInvoice($workspace, $company, $schedule);

        Artisan::call('svc:billing:preflight-schedule-generation', ['--through' => '2026-08-15']);
        $output = Artisan::output();

        foreach ([$workspace->name, $workspace->slug, $company->name, $company->slug,
            $agreement->title, (string) $invoice->invoice_number] as $identifier) {
            $this->assertStringNotContainsString($identifier, $output);
        }
    }

    public function test_an_unknown_format_is_rejected(): void
    {
        $this->assertSame(2, Artisan::call('svc:billing:preflight-schedule-generation', ['--format' => 'csv']));
    }

    public function test_an_unreadable_through_date_is_rejected_rather_than_guessed(): void
    {
        $this->assertSame(2, Artisan::call('svc:billing:preflight-schedule-generation', ['--through' => 'whenever']));
    }

    /**
     * @return array{0: Workspace, 1: ClientCompany, 2: ClientAgreement, 3: ClientBillingSchedule}
     */
    private function scheduledWorkspace(string $slug): array
    {
        $workspace = Workspace::query()->create(['name' => ucfirst($slug).' Workspace', 'slug' => $slug.'-workspace']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id, 'name' => ucfirst($slug).' Client', 'slug' => $slug.'-client',
        ]);
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => ucfirst($slug).' retainer',
            'status' => 'active', 'currency' => 'USD', 'starts_on' => '2026-01-01', 'retainer_minutes' => 600,
        ]);
        $schedule = ClientBillingSchedule::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_agreement_id' => $agreement->id,
            'cadence' => 'monthly', 'next_run_on' => '2026-08-01', 'due_days' => 14, 'currency' => 'USD',
            'line_template' => [[
                'type' => 'service', 'description' => 'Retainer', 'quantity' => '1',
                'unit_amount' => 100000, 'tax_amount' => 0, 'sort_order' => 1,
            ]],
        ]);

        return [$workspace, $company, $agreement, $schedule];
    }

    private function danglingInvoice(Workspace $workspace, ClientCompany $company, ClientBillingSchedule $schedule): ClientInvoice
    {
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id,
            'invoice_number' => 'SECRET-'.uniqid(), 'status' => 'draft', 'currency' => 'USD',
            'subtotal_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0,
        ]);

        $invoice->forceFill([
            'client_billing_schedule_id' => $schedule->id + 500,
            'service_period_start' => '2026-08-01',
            'service_period_end' => '2026-08-31',
        ])->save();

        return $invoice;
    }
}
