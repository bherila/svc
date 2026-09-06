<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientBillingSchedule;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The deployment gate: the exit code, the shape, and what it never prints.
 *
 * `ScheduleRefusalAuditorTest` covers which rows are counted and why. This
 * covers the three things a pipeline and an operator depend on and the auditor
 * cannot provide on its own: a non-zero exit when there is something to repair,
 * a JSON contract stable enough to diff between runs, and output containing no
 * identifier - so a run against real client billing records can be pasted into
 * a public issue.
 */
final class AuditScheduleRefusalsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_clean_database_exits_zero_and_says_what_it_did_not_check(): void
    {
        $this->scheduledWorkspace('clean');

        $this->assertSame(0, Artisan::call('svc:billing:audit-schedule-refusals'));
        $output = Artisan::output();

        $this->assertStringContainsString('No invoice would refuse generation', $output);

        // The one thing this audit cannot answer must be said out loud on the
        // green path, where it would otherwise be read as an all-clear: a
        // partial period overlap depends on the period being billed and no
        // query can count it.
        $this->assertStringContainsString('not a guarantee', $output);
    }

    public function test_a_refusing_row_exits_non_zero_so_a_pipeline_can_gate_on_it(): void
    {
        [$workspace, $company, , $schedule] = $this->scheduledWorkspace('halted');
        $this->danglingInvoice($workspace, $company, $schedule);

        $this->assertSame(1, Artisan::call('svc:billing:audit-schedule-refusals'));
        $this->assertStringContainsString('would make a schedule refuse to generate', Artisan::output());
    }

    public function test_the_json_shape_is_the_documented_contract(): void
    {
        [$workspace, $company, , $schedule] = $this->scheduledWorkspace('json');
        $this->danglingInvoice($workspace, $company, $schedule);

        $this->assertSame(1, Artisan::call('svc:billing:audit-schedule-refusals', ['--format' => 'json']));
        $decoded = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame([
            'invoices' => 1,
            'candidates' => 1,
            'would_refuse_schedule_generation' => 1,
            'dangling_schedule_link' => 1,
            'dangling_agreement_link' => 0,
            'contradictory_lineage' => 0,
            'unknown_status' => 0,
            'incomplete_period_on_an_owned_row' => 0,
            'unattributed_and_contested' => 0,
            'schedules_halted' => 1,
            'schedules' => 1,
        ], $decoded['summary']);
    }

    /**
     * Counts only. Enforced by the shape of `ScheduleRefusalCounts`, and
     * asserted here anyway, because the whole point of running this against
     * production is being able to paste the result somewhere public.
     */
    public function test_the_report_names_no_row_company_or_workspace(): void
    {
        [$workspace, $company, $agreement, $schedule] = $this->scheduledWorkspace('secret');
        $invoice = $this->danglingInvoice($workspace, $company, $schedule);

        Artisan::call('svc:billing:audit-schedule-refusals');
        $output = Artisan::output();

        foreach ([$workspace->name, $workspace->slug, $company->name, $company->slug,
            $agreement->title, (string) $invoice->invoice_number] as $identifier) {
            $this->assertStringNotContainsString($identifier, $output);
        }
    }

    public function test_an_unknown_format_is_rejected(): void
    {
        $this->assertSame(2, Artisan::call('svc:billing:audit-schedule-refusals', ['--format' => 'csv']));
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
            'line_template' => [['description' => 'Retainer', 'quantity' => 1, 'unit_amount' => 1000]],
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
