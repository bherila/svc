<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InterimOverageGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

/**
 * The January and February interim drafts of `InterimClaimValidityTest`,
 * issued at the same time from two MariaDB processes.
 *
 * Each claim is checked against the cycle's charged claims, so two issues of
 * one agreement's interims must not be checked concurrently: each would pass
 * against a cycle the other is about to change. The first is held just after
 * issue() has locked its invoice; the second is started and given time to get
 * through. Serialised on the agreement it cannot, and when it can it finds the
 * first one charged and is refused - as stale when it is February, out of order
 * when it is January.
 */
final class InterimClaimConcurrencyTest extends TestCase
{
    use UsesAProbeDatabase;

    private const COMPETITOR_SECONDS = 5.0;

    /** @return iterable<string, array{string, bool}> */
    public static function orders(): iterable
    {
        yield 'january held, february competing' => ['january', true];
        yield 'february held, january competing' => ['february', false];
    }

    #[DataProvider('orders')]
    public function test_two_interim_claims_of_one_cycle_cannot_both_issue_concurrently(string $heldMonth, bool $competitorMayRegenerate): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Cross-process agreement serialisation is exercised in the MariaDB lane.');
        }

        $this->bootProbeDatabase('interim_race');
        Artisan::call('migrate', ['--database' => 'interim_race', '--force' => true]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('interim_race');
        Schema::clearResolvedInstance('db.schema');
        $processes = [];
        $barrier = sys_get_temp_dir().'/svc-interim-race-'.Str::lower(Str::random(16));
        $paused = $barrier.'-paused';
        $release = $barrier.'-release';
        try {
            $workspace = Workspace::query()->create(['name' => 'Synthetic interim race', 'slug' => 'synthetic-interim-race']);
            $user = User::factory()->create();
            $workspace->memberships()->create(['user_id' => $user->id, 'role' => 'owner']);
            $company = ClientCompany::query()->create([
                'workspace_id' => $workspace->id, 'name' => 'Synthetic interim race client', 'slug' => 'synthetic-interim-race-client',
            ]);
            $project = ClientProject::query()->create([
                'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic interim race project',
            ]);
            $agreement = ClientAgreement::query()->create([
                'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic quarterly retainer',
                'status' => 'active', 'currency' => 'USD', 'starts_on' => '2024-01-01', 'retainer_minutes' => 600,
                'retainer_amount' => 150000, 'catch_up_threshold_minutes' => 0, 'hourly_rate_amount' => 20000,
                'rollover_months' => 0, 'billing_cadence' => 'quarterly', 'bill_overage_interim' => true,
            ])->fresh();
            foreach (['2024-01-10', '2024-02-10'] as $on) {
                ClientTimeEntry::query()->create([
                    'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id,
                    'user_id' => $user->id, 'worked_on' => $on, 'minutes' => 900, 'description' => 'Synthetic work',
                    'is_billable' => true, 'is_deferred' => false, 'status' => 'approved', 'currency' => 'USD',
                ]);
            }
            $generator = app(InterimOverageGenerator::class);
            $drafts = [
                'january' => $generator->generateInterimOverageInvoice($company, Carbon::parse('2024-01-01'), $agreement->fresh()),
                'february' => $generator->generateInterimOverageInvoice($company, Carbon::parse('2024-02-01'), $agreement->fresh()),
            ];
            $this->assertSame('5.0000', (string) $drafts['january']?->hours_billed_at_rate);
            $this->assertSame('10.0000', (string) $drafts['february']?->hours_billed_at_rate);
            $competingMonth = $heldMonth === 'january' ? 'february' : 'january';

            $held = $this->worker($workspace, $drafts[$heldMonth], $paused, $release);
            $competitor = $this->worker($workspace, $drafts[$competingMonth], null, null);
            $processes = [$held, $competitor];
            $held->start();
            $this->awaitFile($held, $paused);
            $competitor->start();
            $deadline = microtime(true) + self::COMPETITOR_SECONDS;
            while ($competitor->isRunning() && microtime(true) < $deadline) {
                usleep(10_000);
            }
            $competitorFinishedWhileHeld = ! $competitor->isRunning();
            $this->assertTrue(touch($release), 'Could not release the held issue.');
            $held->wait();
            $competitor->wait();
            $output = $held->getOutput().$held->getErrorOutput().$competitor->getOutput().$competitor->getErrorOutput();

            DB::purge('interim_race');
            $charged = (float) ClientInvoice::query()
                ->where('workspace_id', $workspace->id)
                ->where('invoice_kind', 'interim_overage')
                ->whereIn('status', ['issued', 'partially_paid', 'paid'])
                ->sum('hours_billed_at_rate');
            $context = json_encode(['competitor_finished_while_held' => $competitorFinishedWhileHeld, 'charged_hours' => $charged], JSON_THROW_ON_ERROR).' '.$output;

            $this->assertLessThanOrEqual(10.0, $charged, 'Both interim claims were charged: 15 hours against 10 of excess. '.$context);
            $this->assertFalse($competitorFinishedWhileHeld, 'The competitor got through while the first held the cycle. '.$context);
            $this->assertSame('success', $this->workerResult($held)['outcome'], $context);
            $this->assertSame(['outcome' => 'refused', 'regenerate' => $competitorMayRegenerate], $this->workerResult($competitor), $context);
            $this->assertSame('draft', ClientInvoice::query()->where('workspace_id', $workspace->id)->findOrFail($drafts[$competingMonth]->id)->status, $context);
        } finally {
            foreach ($processes as $process) {
                $process->stop(0);
            }
            @unlink($paused);
            @unlink($release);
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
        }
    }

    private function worker(Workspace $workspace, ?ClientInvoice $invoice, ?string $paused, ?string $release): Process
    {
        $input = base64_encode(json_encode([
            'connection' => config('database.connections.'.DB::getDefaultConnection()),
            'workspace' => $workspace->id,
            'invoice' => $invoice?->id,
            'paused' => $paused,
            'release' => $release,
        ], JSON_THROW_ON_ERROR));

        return new Process(
            [PHP_BINARY, base_path('tests/Fixtures/Billing/interim-issue-race-worker.php'), $input],
            base_path(),
            ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null', 'MAIL_MAILER' => 'array'],
            null,
            90,
        );
    }

    private function awaitFile(Process $process, string $path): void
    {
        $deadline = microtime(true) + 20;
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
