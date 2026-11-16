<?php

namespace Tests\Feature\Engagement;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\Workspace;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

/**
 * Two overlapping agreements activated at once, in separate processes (#718).
 *
 * Activation refuses an agreement that overlaps another active one in its
 * scope, under the company lock every activation takes. That check is an
 * ordinary read, so it sees the transaction's snapshot - fixed at the first
 * ordinary read, which for a draft was the package-sign-up check, taken before
 * the company lock. An activation that committed while this one waited for the
 * company was then invisible to it, and both agreements went live.
 *
 * The first worker is held with its snapshot taken; the second activates the
 * overlapping agreement meanwhile; then the first is let go. Exactly one may
 * end up active. Held at top level, just after the sign-up check, the fix is
 * the company lock taken before any ordinary read. Held inside a caller's
 * transaction that has already read - the agent API's executor, package
 * enrollment - no lock taken later can move the snapshot, so the company's
 * agreement revision, read through the snapshot and through the lock, refuses
 * it.
 */
final class AgreementActivationConcurrencyTest extends TestCase
{
    use UsesAProbeDatabase;

    /** How long the second activation is given to finish while the first is held. */
    private const SECOND_SECONDS = 3.0;

    /** @return iterable<string, array{string|null}> */
    public static function callers(): iterable
    {
        yield 'its own transaction' => [null];
        yield 'a caller transaction that read first' => ['read'];
        // A sign-up invoice spending credit writes the company row first, so
        // a revision kept there would read current through the snapshot.
        yield 'a caller transaction that read first and wrote the company row' => ['spend'];
    }

    #[DataProvider('callers')]
    public function test_two_overlapping_activations_cannot_both_go_live(?string $outer): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Snapshot timing across sessions is exercised in the MariaDB lane.');
        }

        $this->bootProbeDatabase('activation_race');
        Artisan::call('migrate', ['--database' => 'activation_race', '--force' => true]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('activation_race');
        Schema::clearResolvedInstance('db.schema');
        $processes = [];
        $barrier = sys_get_temp_dir().'/svc-activation-race-'.Str::lower(Str::random(16));
        $paths = ['paused' => $barrier.'-paused', 'release' => $barrier.'-release'];
        try {
            $workspace = Workspace::query()->create(['name' => 'Synthetic activation race', 'slug' => 'synthetic-activation-race']);
            $company = ClientCompany::query()->create([
                'workspace_id' => $workspace->id, 'name' => 'Synthetic activation client', 'slug' => 'synthetic-activation-client',
            ]);
            // Company-wide and open-ended, so each overlaps the other.
            $draft = fn (string $title, string $startsOn): ClientAgreement => ClientAgreement::query()->create([
                'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => $title, 'status' => 'draft',
                'currency' => 'USD', 'starts_on' => $startsOn, 'retainer_minutes' => 120, 'retainer_amount' => 30000,
                'catch_up_threshold_minutes' => 0, 'hourly_rate_amount' => 12000, 'billing_cadence' => 'monthly',
                'bill_overage_interim' => false, 'rollover_months' => 0,
            ]);
            $held = $draft('Synthetic held agreement', '2026-03-01');
            $other = $draft('Synthetic overlapping agreement', '2026-01-01');

            $first = $this->worker($workspace->id, $held->id, $paths['paused'], $paths['release'], $outer);
            $second = $this->worker($workspace->id, $other->id, null, null, null);
            $processes = [$first, $second];
            $first->start();
            $this->awaitFile($first, $paths['paused']);
            // Held with its snapshot taken. The other activation either
            // commits now or waits for the company the held one has locked.
            $second->start();
            $deadline = microtime(true) + self::SECOND_SECONDS;
            while ($second->isRunning() && microtime(true) < $deadline) {
                usleep(10_000);
            }
            $this->assertTrue(touch($paths['release']), 'Could not release the held activation.');
            $first->wait();
            $second->wait();
            $results = [$this->workerResult($first), $this->workerResult($second)];
            $context = json_encode($results, JSON_THROW_ON_ERROR);

            DB::purge('activation_race');
            $active = ClientAgreement::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)
                ->where('status', 'active')->count();
            $this->assertSame(1, $active, 'Two overlapping agreements went live at once. '.$context);
            $this->assertEqualsCanonicalizing(['activated', 'refused'], array_column($results, 'outcome'), $context);
        } finally {
            foreach ($processes as $process) {
                $process->stop(0);
            }
            foreach ($paths as $path) {
                @unlink($path);
            }
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
        }
    }

    private function worker(int $workspaceId, int $agreementId, ?string $paused, ?string $release, ?string $outer): Process
    {
        $input = base64_encode(json_encode([
            'connection' => config('database.connections.'.DB::getDefaultConnection()),
            'workspace' => $workspaceId,
            'agreement' => $agreementId,
            'paused' => $paused,
            'release' => $release,
            'outer' => $outer,
        ], JSON_THROW_ON_ERROR));

        return new Process(
            [PHP_BINARY, base_path('tests/Fixtures/Engagement/agreement-activation-race-worker.php'), $input],
            base_path(),
            ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null', 'MAIL_MAILER' => 'array'],
            null,
            60,
        );
    }

    private function awaitFile(Process $process, string $path): void
    {
        $deadline = microtime(true) + 15;
        while (! is_file($path) && $process->isRunning() && microtime(true) < $deadline) {
            usleep(10_000);
        }

        $this->assertFileExists($path, $process->getOutput().$process->getErrorOutput());
    }

    /** @return array<string, mixed> */
    private function workerResult(Process $process): array
    {
        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        $lines = explode("\n", trim($process->getOutput()));

        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }
}
