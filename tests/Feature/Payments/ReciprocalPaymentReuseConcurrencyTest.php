<?php

namespace Tests\Feature\Payments;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePaymentConnection;
use App\Services\Payments\WorkspacePaymentConnections;
use App\Support\Payments\ConnectionOrigin;
use App\Support\Payments\PaymentProcessor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

/** Real reciprocal reuse requests, synchronized at SQL locks and an observed InnoDB wait. */
final class ReciprocalPaymentReuseConcurrencyTest extends TestCase
{
    use UsesAProbeDatabase;

    private string $originalConnection;

    /** @var list<Process> */
    private array $workers = [];

    /** @var list<string> */
    private array $barriers = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Reciprocal row-lock contention runs in the MariaDB lane.');
        }
        $this->bootProbeDatabase('payment_reuse_concurrency');
        $this->assertSame(0, Artisan::call('migrate', [
            '--database' => 'payment_reuse_concurrency', '--schema-path' => database_path('schema/mariadb-schema.sql'), '--force' => true,
        ]));
        $this->originalConnection = DB::getDefaultConnection();
        DB::setDefaultConnection('payment_reuse_concurrency');
        Schema::clearResolvedInstance('db.schema');
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            $worker->stop(0);
        }
        foreach ($this->barriers as $path) {
            @unlink($path);
        }
        if (isset($this->originalConnection)) {
            DB::setDefaultConnection($this->originalConnection);
            Schema::clearResolvedInstance('db.schema');
        }
        parent::tearDown();
    }

    /** @return iterable<string, array{bool}> */
    public static function startOrders(): iterable
    {
        yield 'higher source id starts first' => [true];
        yield 'lower source id starts first' => [false];
    }

    #[DataProvider('startOrders')]
    public function test_reciprocal_reuse_terminates_as_one_success_and_one_ordinary_conflict(bool $higherSourceFirst): void
    {
        $owner = User::factory()->create();
        $first = Workspace::query()->create(['name' => 'Synthetic first', 'slug' => 'synthetic-first']);
        $second = Workspace::query()->create(['name' => 'Synthetic second', 'slug' => 'synthetic-second']);
        $connections = new WorkspacePaymentConnections;
        $sources = [];
        foreach ([$first, $second] as $workspace) {
            $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
            $sources[] = $connections->connect($workspace, PaymentProcessor::Stripe, ConnectionOrigin::Entered, $owner, [
                'external_account_id' => 'acct_synthetic_'.$workspace->id, 'secret' => 'sk_test_synthetic_reuse_'.$workspace->id,
            ]);
        }
        [$target, $peerTarget, $source, $peerSource] = $higherSourceFirst
            ? [$first, $second, $sources[1], $sources[0]]
            : [$second, $first, $sources[0], $sources[1]];
        [$held, , $ready, $release] = $this->worker($target, $source, $owner, true);
        [$peer, $started] = $this->worker($peerTarget, $peerSource, $owner, false);
        $held->start();
        $this->awaitFile($held, $ready);
        $peer->start();
        $this->awaitFile($peer, $started);
        $session = json_decode(file_get_contents($started), true, flags: JSON_THROW_ON_ERROR)['connection_id'];
        $this->awaitLockWait($peer, $session);
        $this->assertTrue(touch($release));
        $held->wait();
        $peer->wait();
        $heldResult = $this->workerResult($held);
        $peerResult = $this->workerResult($peer);

        $this->assertSame('success', $heldResult['outcome'], json_encode($heldResult));
        $this->assertSame('refused', $peerResult['outcome'], json_encode($peerResult));
        $this->assertSame('That connection cannot be reused.', $peerResult['message']);
        $this->assertSame($source->id, $heldResult['source_id']);
        $this->assertCount(2, WorkspacePaymentConnection::query()->whereNull('revoked_at')->get());
        $this->assertNotNull($peerSource->fresh()->revoked_at);
        $this->assertNull($source->fresh()->revoked_at);
        foreach (WorkspacePaymentConnection::query()->whereNull('revoked_at')->get() as $active) {
            $this->assertNotNull((new WorkspacePaymentConnections)->credentialSource($active));
        }
    }

    /** @return array{Process, string, string, string} */
    private function worker(Workspace $target, WorkspacePaymentConnection $source, User $actor, bool $pause): array
    {
        $prefix = sys_get_temp_dir().'/svc-payment-reuse-'.Str::lower(Str::random(16));
        [$started, $ready, $release] = [$prefix.'-started', $prefix.'-ready', $prefix.'-release'];
        array_push($this->barriers, $started, $ready, $release);
        $input = ['connection' => config('database.connections.'.DB::getDefaultConnection()),
            'target' => $target->id, 'source' => $source->public_id, 'actor' => $actor->id, 'pause' => $pause,
            'started' => $started, 'ready' => $ready, 'release' => $release];
        $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/Concurrency/payment-reuse-worker.php'), base64_encode(json_encode($input, JSON_THROW_ON_ERROR))],
            base_path(), ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync'], null, 60);
        $this->workers[] = $worker;

        return [$worker, $started, $ready, $release];
    }

    private function awaitFile(Process $process, string $path): void
    {
        $deadline = microtime(true) + 20;
        while (! is_file($path) && $process->isRunning() && microtime(true) < $deadline) {
            usleep(10_000);
        }
        $this->assertFileExists($path, $process->getOutput().$process->getErrorOutput());
    }

    private function awaitLockWait(Process $process, int $session): void
    {
        $deadline = microtime(true) + 12;
        $lastLook = 0.0;
        while ($process->isRunning() && microtime(true) < $deadline) {
            if (microtime(true) - $lastLook >= 1.0) {
                $lastLook = microtime(true);
                if (DB::table('information_schema.innodb_trx')->where('trx_mysql_thread_id', $session)->where('trx_state', 'LOCK WAIT')->exists()) {
                    return;
                }
            }
            usleep(10_000);
        }
        $this->fail('The reciprocal request must reach an actual InnoDB lock wait: '.$process->getOutput().$process->getErrorOutput());
    }

    /** @return array<string, mixed> */
    private function workerResult(Process $process): array
    {
        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        $lines = explode("\n", trim($process->getOutput()));

        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }
}
