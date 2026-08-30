<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The audit that sizes #134 before it is fixed.
 *
 * Three conditions decide whether an agreement is affected, and each of them is
 * asserted here by its own exclusion rather than by one happy path: an audit
 * that counted every agreement carrying an initial rollover would report a
 * population several times the real one, and "no agreement is affected" is the
 * answer this command exists to be trusted about.
 */
class AuditOpeningRolloverCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_agreement_with_an_initial_rollover_and_a_rollover_policy_is_counted(): void
    {
        $this->agreement(['initial_rollover_minutes' => 600, 'rollover_months' => 1]);

        $summary = $this->summary();

        $this->assertSame(1, $summary['affected']);
        $this->assertSame(600, $summary['capacity_at_stake_minutes']);
        $this->assertSame(1, $summary['longest_rollover_months']);
    }

    public function test_a_cadence_agreement_is_not_counted_however_large_its_initial_rollover(): void
    {
        // The seed sits after the cadence branch has already returned, so an
        // agreement with period retainer terms never reaches it. Counted the
        // same as the monthly one, this would be the largest single source of
        // overstatement in the report.
        $this->agreement([
            'initial_rollover_minutes' => 6000,
            'rollover_months' => 3,
            'period_retainer_minutes' => 1800,
        ]);

        $summary = $this->summary();

        $this->assertSame(1, $summary['with_initial_rollover'], 'It carries one');
        $this->assertSame(0, $summary['legacy_monthly_of_those'], 'But never reaches the seed');
        $this->assertSame(0, $summary['affected']);
        $this->assertSame(0, $summary['capacity_at_stake_minutes']);
    }

    public function test_an_agreement_without_a_rollover_policy_is_not_counted(): void
    {
        // The seed month is only reachable by the following month through
        // RolloverCalculator. With no policy the capacity expires unused in the
        // month it is granted, so no invoice ever sees it and repairing the
        // read changes nothing for this agreement.
        $this->agreement(['initial_rollover_minutes' => 600, 'rollover_months' => 0]);

        $summary = $this->summary();

        $this->assertSame(1, $summary['legacy_monthly_of_those'], 'It reaches the seed');
        $this->assertSame(0, $summary['affected'], 'But nothing carries it forward');
    }

    public function test_an_agreement_with_no_initial_rollover_is_not_counted(): void
    {
        $this->agreement(['initial_rollover_minutes' => 0, 'rollover_months' => 6]);

        $summary = $this->summary();

        $this->assertSame(1, $summary['agreements']);
        $this->assertSame(0, $summary['with_initial_rollover']);
        $this->assertSame(0, $summary['affected']);
    }

    public function test_the_report_names_no_workspace_company_or_agreement(): void
    {
        // The value of this command is that its output can be pasted into a
        // public issue. A count is safe to publish; the name of the client it
        // counted is not.
        $this->agreement(['initial_rollover_minutes' => 600, 'rollover_months' => 1]);

        $this->assertSame(0, Artisan::call('svc:billing:audit-opening-rollover'));
        $report = Artisan::output();

        $this->assertStringContainsString('Capacity at stake', $report, 'The report ran');
        foreach (['Rollover Workspace', 'Rollover Client', 'Carried retainer', 'rollover-workspace', 'rollover-client'] as $secret) {
            $this->assertStringNotContainsString($secret, $report);
        }
    }

    public function test_an_unknown_format_is_refused(): void
    {
        $this->artisan('svc:billing:audit-opening-rollover --format=yaml')->assertExitCode(2);
    }

    /**
     * The summary as JSON.
     *
     * @return array<string, int>
     */
    private function summary(): array
    {
        $this->assertSame(0, Artisan::call('svc:billing:audit-opening-rollover', ['--format' => 'json']));

        /** @var array{summary: array<string, int>} $decoded */
        $decoded = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

        return $decoded['summary'];
    }

    /** @param array<string, mixed> $terms */
    private function agreement(array $terms): ClientAgreement
    {
        $workspace = Workspace::query()->create([
            'name' => 'Rollover Workspace',
            'slug' => 'rollover-workspace-'.uniqid(),
        ]);

        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Rollover Client',
            'slug' => 'rollover-client-'.uniqid(),
        ]);

        return ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'title' => 'Carried retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'billing_cadence' => 'monthly',
            'retainer_minutes' => 600,
            'initial_rollover_minutes' => $terms['initial_rollover_minutes'],
            'rollover_months' => $terms['rollover_months'],
            'period_retainer_minutes' => $terms['period_retainer_minutes'] ?? null,
        ]);
    }
}
