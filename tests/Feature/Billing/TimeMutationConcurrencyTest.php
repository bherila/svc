<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\TimeEntryMutationService;
use App\Services\Billing\InvoiceFromTimeService;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

final class TimeMutationConcurrencyTest extends TestCase
{
    use UsesAProbeDatabase;

    public static function interleavings(): iterable
    {
        yield 'allocation after probe' => ['allocate', false];
        yield 'allocation in an existing snapshot transaction' => ['allocate', true];
        yield 'issuance after probe' => ['issue', false];
    }

    #[DataProvider('interleavings')]
    public function test_competing_invoice_writes_cannot_leave_unapproved_time_billed(string $operation, bool $nested): void
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
            $armed = true;
            $invoiceId = $operation === 'issue'
                ? app(InvoiceFromTimeService::class)->create($workspace, $company, ['invoice_number' => 'SYNTHETIC-ISSUANCE', 'currency' => 'USD'], [$entry->public_id])->id
                : null;
            // The listener runs after the ordinary SELECT has established A's
            // snapshot, but before it acquires any domain lock. B must commit
            // before the callback returns; process startup/sleeps are not a barrier.
            DB::listen(function (QueryExecuted $query) use (&$armed, &$invoiceId, $workspace, $company, $entry, $operation, $nested): void {
                if (! $armed || $query->connectionName !== 'time_race' || DB::transactionLevel() !== ($nested ? 2 : 1)
                    || ! str_starts_with($query->sql, 'select * from `client_time_entries`')
                    || str_contains($query->sql, 'for update')) {
                    return;
                }
                $armed = false;
                $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/Billing/time-allocation-worker.php')], base_path(),
                    ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null'], json_encode([
                        'connection' => config('database.connections.time_race'), 'workspace' => $workspace->id,
                        'company' => $company->id, 'entry' => $entry->id, 'operation' => $operation, 'invoice' => $invoiceId,
                    ], JSON_THROW_ON_ERROR)."\n", 20);
                $worker->mustRun();
                $result = json_decode(trim($worker->getOutput()), true, flags: JSON_THROW_ON_ERROR);
                if ($operation === 'allocate') {
                    $this->assertSame($entry->lock_version, $result['version'], 'Allocation alone does not invalidate the optimistic version.');
                }
                $invoiceId = $result['invoice'];
            });
            $refused = false;
            try {
                $mutate = fn () => app(TimeEntryMutationService::class)->unapprove($workspace, $entry, $actor, AgentApiVersion::for($entry));
                if ($nested) {
                    DB::transaction(function () use ($workspace, $mutate): void {
                        Workspace::query()->whereKey($workspace->id)->firstOrFail();
                        $mutate();
                    });
                } else {
                    $mutate();
                }
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(409, $exception->getStatusCode());
                $refused = true;
            }
            $this->assertFalse($armed, 'The allocation must commit after the probe.');
            DB::purge('time_race'); // Observe the committed outcome from a fresh connection.
            $fresh = ClientTimeEntry::query()->findOrFail($entry->id);
            $invoice = ClientInvoice::query()->findOrFail($invoiceId);
            if ($refused) {
                $this->assertSame($operation === 'issue' ? 'invoiced' : 'approved', $fresh->status);
                $this->assertSame(12000, $fresh->billing_rate_amount);
                if ($operation === 'allocate') {
                    $this->assertSame($entry->lock_version, $fresh->lock_version);
                }
                $this->assertSame($actor->id, $fresh->approved_by_user_id);
                $this->assertSame(1, $fresh->invoiceLines()->count());
                $this->assertSame(12000, $invoice->total_amount);
                $this->assertSame(12000, $invoice->lines()->sole()->total_amount);
            } else {
                $this->assertSame('draft', $fresh->status);
                $this->assertSame(0, $fresh->invoiceLines()->count());
                $this->assertSame(0, $invoice->lines()->count());
                $this->assertSame(0, $invoice->total_amount);
            }
        } finally {
            config(['database.default' => $original]);
        }
    }
}
