<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientBillingSchedule;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\Workspace;
use App\Services\Billing\AgreementHandoverAudit;
use App\Services\Billing\AgreementHandoverRepository;
use App\Services\Billing\BillingScheduleService;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\CreateBillingScheduleAction;
use App\Services\Billing\ScheduleGenerationPreflight;
use App\Support\Billing\ScheduleDefect;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

final class AgreementHandoverTest extends TestCase
{
    use RefreshDatabase, WritesLegacyCrossTenantRows;

    /** @return array<string, array{string, string, string}> */
    public static function cadences(): array
    {
        return ['monthly' => ['monthly', '2026-01-15', '2026-02-01'],
            'quarterly' => ['quarterly', '2026-02-15', '2026-04-01'],
            'annual' => ['annual', '2026-06-15', '2027-01-01']];
    }

    #[DataProvider('cadences')]
    public function test_preflight_and_schedule_generation_refuse_the_same_midperiod_successor(string $cadence, string $mid, string $boundary): void
    {
        [$workspace, $company, $agreement] = $this->terms($cadence);
        $successor = $this->successor($agreement, $mid);
        $schedule = ClientBillingSchedule::query()->create($this->scheduleData($workspace, $company, $agreement));
        $report = app(ScheduleGenerationPreflight::class)->run($workspace, CarbonImmutable::parse('2026-01-01'));
        $this->assertSame(1, $report->wouldHalt);
        $this->assertSame(1, $report->periodsClassified);
        $this->assertSame(1, $report->defectsByKind[ScheduleDefect::AgreementHandoverOverlap->value]);
        try {
            app(BillingScheduleService::class)->generateDue($schedule, CarbonImmutable::parse('2026-01-01'));
            $this->fail('Overlapping schedule must refuse.');
        } catch (DomainException $error) {
            $this->assertStringContainsString($successor->public_id, $error->getMessage());
            $this->assertStringContainsString($mid, $error->getMessage());
        }
        $this->assertDatabaseCount('client_invoices', 0);
        $this->assertSame('2026-01-01', $schedule->fresh()->next_run_on->toDateString());
        $this->artisan('svc:billing:preflight-schedule-generation', ['--through' => '2026-01-01', '--format' => 'json'])->assertExitCode(1);
        $successor->update(['starts_on' => $boundary]);
        $clear = app(ScheduleGenerationPreflight::class)->run($workspace, CarbonImmutable::parse('2026-01-01'));
        $this->assertSame(0, $clear->wouldHalt);
        $invoices = app(BillingScheduleService::class)->generateDue($schedule, CarbonImmutable::parse('2026-01-01'));
        $this->assertCount(1, $invoices);
        $this->assertSame('issued', $invoices[0]->status);
    }

    #[DataProvider('cadences')]
    public function test_cadence_generation_refuses_then_accepts_the_existing_end_date_proration(string $cadence, string $mid, string $boundary): void
    {
        [$workspace, $company, $agreement] = $this->terms($cadence);
        $this->successor($agreement, $mid);
        $engine = app(ClientInvoicingService::class);
        try {
            $engine->generateCadencePeriod($company, $agreement, Carbon::parse('2026-01-01'));
            $this->fail('Overlapping cadence must refuse.');
        } catch (DomainException $error) {
            $this->assertStringContainsString('successor agreement', $error->getMessage());
        }
        $this->assertDatabaseCount('client_invoices', 0);
        // The caller's stale model must not override corrected terms read under lock.
        ClientAgreement::query()->where('workspace_id', $workspace->id)->whereKey($agreement->id)
            ->update(['ends_on' => CarbonImmutable::parse($mid)->subDay()->toDateString()]);
        $invoice = $engine->generateCadencePeriod($company, $agreement, Carbon::parse('2026-01-01'));
        $this->assertSame('draft', $invoice->status);
        $this->assertSame($agreement->id, $invoice->client_agreement_id);
        $this->assertSame(1, ClientInvoice::query()->count());
    }

    public function test_creation_refuses_the_first_period_before_reserving_a_schedule(): void
    {
        [$workspace, $company, $agreement] = $this->terms('monthly');
        $this->successor($agreement, '2026-01-15');
        $data = $this->scheduleData($workspace, $company, $agreement) + ['client_agreement' => $agreement->public_id];
        try {
            app(CreateBillingScheduleAction::class)->create($workspace, $company, $data);
            $this->fail('Overlapping schedule must not be created.');
        } catch (DomainException) {
            $this->assertDatabaseCount('client_billing_schedules', 0);
        }
    }

    public function test_bulk_generation_sees_a_successor_outside_the_one_month_selection_window(): void
    {
        $this->travelTo(Carbon::parse('2026-01-01 12:00:00'));
        [$workspace, $company, $agreement] = $this->terms('annual');
        $agreement->update(['bill_overage_interim' => true]);
        $this->successor($agreement, '2026-06-15');
        $result = app(ClientInvoicingService::class)->generateAllInvoices($company);
        $this->assertSame([], $result['generated']);
        $this->assertSame('agreement_handover_overlap', $result['skipped'][0]['reason_code']);
        $this->assertDatabaseCount('client_invoices', 0);
    }

    public function test_successors_are_scoped_by_workspace_company_and_project_and_include_future_history(): void
    {
        [$workspace, $company, $agreement] = $this->terms('annual');
        [$other, $otherCompany, $otherAgreement] = $this->terms('monthly', 'other');
        $this->successor($otherAgreement, '2026-01-15');
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Independent project']);
        $this->successor($agreement, '2026-02-01')->update(['client_project_id' => $project->id]);
        $draft = $this->successor($agreement, '2026-03-01');
        $draft->update(['status' => 'draft']);
        $repository = app(AgreementHandoverRepository::class);
        $this->assertNull($repository->forAgreement($agreement));
        $future = $this->successor($agreement, '2026-10-01');
        $handover = $repository->forAgreement($agreement);
        $this->assertSame($future->public_id, $handover?->successorId);
        $this->assertSame('2026-10-01', $handover?->startsOn);
        $this->assertSame([$agreement->id], array_keys($repository->forCompanies([['workspace_id' => $workspace->id, 'client_company_id' => $company->id]])));
        $this->assertSame(0, app(ScheduleGenerationPreflight::class)->run($other)->schedules);
    }

    public function test_historical_audit_reports_issued_coverage_without_rewriting_it_and_excludes_other_tenants(): void
    {
        [$workspace, $company, $agreement] = $this->terms('monthly');
        $successor = $this->successor($agreement, '2026-01-15');
        $invoice = ClientInvoice::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
            'client_agreement_id' => $agreement->id, 'invoice_number' => 'TEST-HANDOVER', 'status' => 'paid', 'currency' => 'USD',
            'invoice_kind' => 'cadence_period', 'cycle_start' => '2026-01-01', 'cycle_end' => '2026-01-31', 'total_amount' => 10000]);
        $before = $invoice->refresh()->getRawOriginal();
        [$other] = $this->terms('monthly', 'other');
        $audit = app(AgreementHandoverAudit::class);
        $this->assertSame(['count' => 0, 'invoices' => [], 'truncated' => false], $audit->report($other));
        $report = $audit->report($workspace);
        $this->assertSame(1, $report['count']);
        $this->assertFalse($report['truncated']);
        $this->assertSame([['invoice_id' => $invoice->public_id, 'successor_id' => $successor->public_id,
            'successor_starts_on' => '2026-01-15', 'coverage_start' => '2026-01-01', 'coverage_end' => '2026-01-31']], $report['invoices']);
        $this->assertSame($before, $invoice->fresh()->getRawOriginal());
        $this->artisan('svc:billing:audit-handovers', ['--workspace' => $workspace->public_id, '--format' => 'json'])->assertSuccessful();
        $this->artisan('svc:billing:audit-handovers', ['--workspace' => 'missing', '--format' => 'json'])->assertExitCode(2);
        $invoice->update(['status' => 'void']);
        $this->assertSame(0, $audit->report($workspace)['count']);
    }

    public function test_audit_uses_schedule_dates_and_legacy_monthly_coverage_and_caps_identifiers(): void
    {
        [$workspace, $company, $agreement] = $this->terms('monthly');
        $this->successor($agreement, '2026-01-15');
        $schedule = ClientBillingSchedule::query()->create($this->scheduleData($workspace, $company, $agreement));
        $base = ['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_agreement_id' => $agreement->id,
            'status' => 'issued', 'currency' => 'USD', 'invoice_kind' => 'cadence_period'];
        ClientInvoice::query()->create($base + ['invoice_number' => 'TEST-SCHEDULE', 'client_billing_schedule_id' => $schedule->id,
            'service_period_start' => '2026-01-01', 'service_period_end' => '2026-01-31']);
        // A legacy invoice's work dates are December, while it sells January.
        ClientInvoice::query()->create($base + ['invoice_number' => 'TEST-LEGACY', 'service_period_start' => '2025-12-01', 'service_period_end' => '2025-12-31']);
        foreach (range(1, 100) as $index) {
            ClientInvoice::query()->create($base + ['invoice_number' => 'TEST-BOUNDED-'.$index, 'cycle_start' => '2026-01-01', 'cycle_end' => '2026-01-31']);
        }
        $report = app(AgreementHandoverAudit::class)->report(null);
        $this->assertSame(102, $report['count']);
        $this->assertCount(100, $report['invoices']);
        $this->assertTrue($report['truncated']);
        foreach (array_slice($report['invoices'], 0, 2) as $row) {
            $this->assertSame('2026-01-01', $row['coverage_start']);
            $this->assertSame('2026-01-31', $row['coverage_end']);
        }
        $this->assertSame([], app(AgreementHandoverRepository::class)->forCompanies([]));
        $this->artisan('svc:billing:audit-handovers')->expectsOutputToContain('102 issued invoice(s)')->assertSuccessful();
    }

    public function test_malformed_legacy_cross_tenant_rows_are_not_successors_or_audit_evidence(): void
    {
        [$workspace, $company, $agreement] = $this->terms('monthly');
        [$other, $otherCompany, $otherAgreement] = $this->terms('monthly', 'other');
        $this->writingLegacyCrossTenantRows(function () use ($other, $company, $workspace, $otherCompany, $otherAgreement): void {
            ClientAgreement::query()->create(['workspace_id' => $other->id, 'client_company_id' => $company->id,
                'title' => 'Synthetic malformed successor', 'status' => 'active', 'currency' => 'USD', 'starts_on' => '2026-01-15']);
            ClientInvoice::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $otherCompany->id,
                'client_agreement_id' => $otherAgreement->id, 'invoice_number' => 'TEST-MALFORMED', 'status' => 'paid', 'currency' => 'USD',
                'cycle_start' => '2026-01-01', 'cycle_end' => '2026-01-31']);
        });
        $this->assertNull(app(AgreementHandoverRepository::class)->forAgreement($agreement));
        $this->successor($otherAgreement, '2026-01-15');
        $this->assertSame(0, app(AgreementHandoverAudit::class)->report($workspace)['count']);
    }

    /** @return array{Workspace, ClientCompany, ClientAgreement} */
    private function terms(string $cadence, string $slug = 'handover'): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic handover', 'slug' => $slug]);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic company', 'slug' => $slug]);
        $agreement = ClientAgreement::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
            'title' => 'Synthetic retainer', 'status' => 'active', 'currency' => 'USD', 'billing_cadence' => $cadence,
            'starts_on' => '2026-01-01', 'retainer_minutes' => 600, 'retainer_amount' => 100000]);

        return [$workspace, $company, $agreement];
    }

    private function successor(ClientAgreement $agreement, string $starts): ClientAgreement
    {
        return ClientAgreement::query()->create(['workspace_id' => $agreement->workspace_id, 'client_company_id' => $agreement->client_company_id,
            'title' => 'Synthetic successor', 'status' => 'active', 'currency' => 'USD', 'starts_on' => $starts]);
    }

    /** @return array<string, mixed> */
    private function scheduleData(Workspace $workspace, ClientCompany $company, ClientAgreement $agreement): array
    {
        return ['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_agreement_id' => $agreement->id,
            'cadence' => $agreement->billing_cadence, 'next_run_on' => '2026-01-01', 'currency' => 'USD', 'due_days' => 14,
            'line_template' => [['type' => 'service', 'description' => 'Synthetic retainer', 'quantity' => '1', 'unit_amount' => 100000, 'tax_amount' => 0, 'sort_order' => 1]]];
    }
}
