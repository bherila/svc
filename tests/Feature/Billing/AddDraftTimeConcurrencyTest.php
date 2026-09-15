<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceFromTimeService;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

final class AddDraftTimeConcurrencyTest extends TestCase
{
    use UsesAProbeDatabase;

    public function test_an_allocation_committed_after_the_snapshot_cannot_be_added_again(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('The allocation snapshot race requires MariaDB.');
        }
        $this->bootProbeDatabase('time_race');
        Artisan::call('migrate', ['--database' => 'time_race', '--force' => true]);
        $original = config('database.default');
        config(['database.default' => 'time_race']);
        try {
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            DB::statement('SET SESSION innodb_snapshot_isolation=OFF');
            $this->assertSame('REPEATABLE-READ', DB::selectOne('SELECT @@session.tx_isolation AS level')->level);
            $workspace = Workspace::query()->create(['name' => 'Synthetic time race', 'slug' => 'synthetic-time-race']);
            $actor = User::factory()->create();
            $workspace->memberships()->create(['user_id' => $actor->id, 'role' => 'admin']);
            $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic client', 'slug' => 'synthetic-client']);
            $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic project']);
            $entry = ClientTimeEntry::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
                'client_project_id' => $project->id, 'user_id' => $actor->id, 'worked_on' => '2026-07-14', 'minutes' => 60,
                'description' => 'Synthetic approved work', 'is_billable' => true, 'status' => 'approved',
                'billing_rate_amount' => 12000, 'billing_rate_source' => 'agreement', 'currency' => 'USD',
                'approved_by_user_id' => $actor->id, 'approved_at' => now()]);
            $target = app(InvoiceFromTimeService::class)->create($workspace, $company, ['invoice_number' => 'SYNTHETIC-TARGET', 'currency' => 'USD'], [], [['type' => 'service', 'description' => 'Synthetic retained fee', 'quantity' => '1', 'unit_amount' => 500]]);
            $refused = false;
            DB::transaction(function () use ($workspace, $company, $entry, $target, &$refused): void {
                ClientTimeEntry::query()->where('workspace_id', $workspace->id)->whereKey($entry->id)->firstOrFail();
                $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/Billing/time-allocation-worker.php')], base_path(),
                    ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null'], json_encode([
                        'connection' => config('database.connections.time_race'), 'workspace' => $workspace->id, 'company' => $company->id,
                        'entry' => $entry->id, 'operation' => 'allocate', 'invoice' => null,
                    ], JSON_THROW_ON_ERROR)."\n", 20);
                $worker->mustRun();
                try {
                    app(InvoiceFromTimeService::class)->addTime($target, $workspace, AgentApiVersion::for($target), [$entry->public_id]);
                } catch (\DomainException $exception) {
                    $this->assertSame('Selected time has already been allocated to an invoice.', $exception->getMessage());
                    $refused = true;
                }
            });
            $this->assertTrue($refused);
            DB::purge('time_race');
            $fresh = ClientInvoice::query()->where('workspace_id', $workspace->id)->findOrFail($target->id);
            $this->assertSame(500, $fresh->total_amount);
            $this->assertSame(1, $fresh->lines()->count());
            $this->assertSame(1, DB::table('client_invoice_line_time_entries')->where('workspace_id', $workspace->id)->where('client_time_entry_id', $entry->id)->count());
            $this->assertSame(12000, ClientInvoice::query()->where('workspace_id', $workspace->id)->where('id', '!=', $target->id)->sole()->total_amount);
        } finally {
            config(['database.default' => $original]);
        }
    }
}
