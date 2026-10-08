<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientBillingSchedule;
use App\Models\ClientCompany;
use App\Models\Workspace;
use App\Services\Billing\CreateBillingScheduleAction;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

final class CreateBillingScheduleConcurrencyTest extends TestCase
{
    use UsesAProbeDatabase;

    public function test_a_schedule_committed_after_the_company_snapshot_returns_a_conflict(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('The schedule creation snapshot race requires MariaDB.');
        }
        $this->bootProbeDatabase('schedule_race');
        Artisan::call('migrate', ['--database' => 'schedule_race', '--force' => true]);
        $original = config('database.default');
        config(['database.default' => 'schedule_race']);
        try {
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            DB::statement('SET SESSION innodb_snapshot_isolation=OFF');
            $this->assertSame('REPEATABLE-READ', DB::selectOne('SELECT @@session.tx_isolation AS level')->level);
            $workspace = Workspace::query()->create(['name' => 'Synthetic schedule race', 'slug' => 'synthetic-schedule-race']);
            $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic client', 'slug' => 'synthetic-client']);
            $agreement = ClientAgreement::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
                'title' => 'Synthetic agreement', 'currency' => 'USD', 'billing_cadence' => 'monthly', 'status' => 'active', 'starts_on' => '2026-01-01']);
            $version = AgentApiVersion::for($agreement);
            $data = ['client_agreement' => $agreement->public_id, 'cadence' => 'monthly', 'next_run_on' => '2026-10-01',
                'due_days' => 30, 'currency' => 'USD', 'line_template' => [['type' => 'service', 'description' => 'Synthetic fee', 'quantity' => '1', 'unit_amount' => 10000]]];
            $refused = false;
            DB::transaction(function () use ($workspace, $company, $data, $version, &$refused): void {
                // Mirror the controller's ordinary company lookup before the
                // action locks the agreement. The child must commit after this
                // snapshot and before the action, without timing-based barriers.
                ClientCompany::query()->where('workspace_id', $workspace->id)->findOrFail($company->id);
                $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/Billing/schedule-creation-worker.php')], base_path(),
                    ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null'], json_encode([
                        'connection' => config('database.connections.schedule_race'), 'workspace' => $workspace->id,
                        'company' => $company->id, 'data' => $data, 'expected_version' => $version,
                    ], JSON_THROW_ON_ERROR)."\n", 20);
                $worker->mustRun();
                $result = json_decode(trim($worker->getOutput()), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame($version, $result['agreement_version'], 'Creating a schedule does not change its parent revision.');
                $this->assertFalse(ClientBillingSchedule::query()->where('workspace_id', $workspace->id)->exists(), 'The committed winner must be invisible to the ordinary snapshot.');
                try {
                    app(CreateBillingScheduleAction::class)->create($workspace, $company, $data, $version);
                } catch (HttpExceptionInterface $exception) {
                    $this->assertSame(409, $exception->getStatusCode());
                    $this->assertSame('This agreement already has a billing schedule; read it before generating invoices.', $exception->getMessage());
                    $refused = true;
                }
                $this->assertSame(1, DB::transactionLevel());
                $this->assertSame(1, Workspace::query()->whereKey($workspace->id)->count(), 'The caller transaction remains usable after the constraint conflict.');
            });
            $this->assertTrue($refused);
            DB::purge('schedule_race');
            $this->assertSame(1, ClientBillingSchedule::query()->where('workspace_id', $workspace->id)->where('client_agreement_id', $agreement->id)->count());
            $this->assertSame($version, AgentApiVersion::for($agreement->fresh()));
        } finally {
            config(['database.default' => $original]);
        }
    }
}
