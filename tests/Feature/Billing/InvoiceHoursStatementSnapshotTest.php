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
use App\Support\Billing\InvoiceHoursStatementRows;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsSyntheticExpenses;
use Tests\TestCase;

/**
 * Ordinary cadence statements, pinned byte for byte.
 *
 * Both the stored snapshot and its layout are compared against files checked
 * in before corrections were given a statement of their own, so a change made
 * for corrections cannot move what an ordinary invoice stores or prints. Set
 * `UPDATE_HOURS_STATEMENT_SNAPSHOTS=1` to rewrite them deliberately.
 */
final class InvoiceHoursStatementSnapshotTest extends TestCase
{
    use BuildsSyntheticExpenses;
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private ClientAgreement $agreement;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-02-20 12:00:00'));
        $this->workspace = $this->syntheticWorkspace('Snapshot');
        $this->company = $this->syntheticCompany($this->workspace, 'Snapshot');
        $this->project = $this->syntheticProject($this->company, 'Snapshot');
        $this->member = $this->syntheticMember($this->workspace, 'Snapshot worker');
        $this->agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'title' => 'Retainer',
            'status' => 'active', 'currency' => 'USD', 'starts_on' => '2026-01-01',
            'retainer_minutes' => 600, 'retainer_amount' => 150000, 'catch_up_threshold_minutes' => 60,
            'hourly_rate_amount' => 15000, 'billing_cadence' => 'monthly', 'rollover_months' => 1,
        ]);
    }

    /** @return iterable<string, array{string}> */
    public static function scenarios(): iterable
    {
        yield 'normal' => ['normal'];
        yield 'catch-up' => ['catch-up'];
        yield 'deferred' => ['deferred'];
        yield 'quarterly' => ['quarterly'];
    }

    #[DataProvider('scenarios')]
    public function test_an_ordinary_statement_is_unchanged(string $scenario): void
    {
        $invoice = match ($scenario) {
            'normal' => $this->monthly([['2026-01-05', 150], ['2026-01-12', 120], ['2026-01-19', 90]]),
            'catch-up' => $this->monthly([['2026-01-12', 1320]]),
            'deferred' => $this->monthly([['2026-01-05', 360], ['2026-01-20', 180, true], ['2026-01-21', 300, true]]),
            'quarterly' => $this->quarterly(),
        };
        $statement = $invoice->hoursStatement();
        $this->assertNotNull($statement);

        $actual = (string) json_encode([
            'stored' => $invoice->fresh()?->hours_statement,
            'rows' => InvoiceHoursStatementRows::for($statement),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
        $path = base_path('tests/Fixtures/Billing/hours-statement/'.$scenario.'.json');

        if (getenv('UPDATE_HOURS_STATEMENT_SNAPSHOTS') === '1') {
            file_put_contents($path, $actual);
        }

        $this->assertFileExists($path);
        $this->assertSame((string) file_get_contents($path), $actual);
    }

    /** @param list<array{0: string, 1: int, 2?: bool}> $entries */
    private function monthly(array $entries): ClientInvoice
    {
        foreach ($entries as $entry) {
            $this->entry($entry[0], $entry[1], $entry[2] ?? false);
        }
        $start = Carbon::parse('2026-01-01');

        return app(ClientInvoicingService::class)->generateInvoice(
            $this->company, $start, $start->copy()->endOfMonth()->startOfDay(), $this->agreement,
        );
    }

    private function quarterly(): ClientInvoice
    {
        $this->agreement->forceFill(['billing_cadence' => 'quarterly', 'retainer_minutes' => 1800, 'retainer_amount' => 450000, 'catch_up_threshold_minutes' => null])->save();
        foreach (['2026-01-10' => 600, '2026-02-10' => 900, '2026-03-10' => 600, '2026-03-20' => 300] as $day => $minutes) {
            $this->entry($day, $minutes);
        }
        $this->travelTo(Carbon::parse('2026-04-02 12:00:00'));
        app(ClientInvoicingService::class)->generateAllInvoices($this->company);

        return ClientInvoice::query()->where('workspace_id', $this->workspace->id)->whereDate('service_period_start', '2026-01-01')->sole();
    }

    private function entry(string $workedOn, int $minutes, bool $deferred = false): void
    {
        ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id, 'user_id' => $this->member->id,
            'worked_on' => $workedOn, 'minutes' => $minutes, 'description' => 'Synthetic snapshot work',
            'is_billable' => true, 'is_deferred' => $deferred, 'status' => 'approved',
            'billing_rate_amount' => 15000, 'currency' => 'USD',
        ]);
    }
}
