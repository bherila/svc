<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientBillingSchedule;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Engagement\AgreementWorkflow;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

/**
 * The two cadence generators, in separate processes, against one agreement and
 * one period.
 *
 * `BillingScheduleService::generateDue()` and `ClientInvoicingService`'s cadence
 * paths each serialise against a copy of themselves. Against each other they
 * used to lock different rows - the schedule and the agreement - so both could
 * read "nothing covers August" and both insert. No unique index rejects the
 * pair: one row carries the schedule id and the other a null.
 *
 * Each worker pauses in `ClientInvoice::creating`, after its guard and before
 * its insert. The test waits for both to arrive there. When the generators
 * share a lock only one can: the other is still waiting for the agreement, and
 * when it gets it the invoice the first one wrote is there to refuse on.
 */
final class CadenceGeneratorConcurrencyTest extends TestCase
{
    use UsesAProbeDatabase;

    /** How long a second generator is given to reach its insert once the first has. */
    private const SECOND_ARRIVAL_SECONDS = 3.0;

    /** @return iterable<string, array{string, string}> */
    public static function orders(): iterable
    {
        yield 'schedule first' => ['schedule', 'agreement'];
        yield 'agreement first' => ['agreement', 'schedule'];
    }

    #[DataProvider('orders')]
    public function test_the_two_cadence_generators_cannot_both_bill_one_period(string $firstOperation, string $secondOperation): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Cross-generator row-lock contention is exercised in the MariaDB lane.');
        }

        $this->bootProbeDatabase('cadence_race');
        Artisan::call('migrate', ['--database' => 'cadence_race', '--force' => true]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('cadence_race');
        Schema::clearResolvedInstance('db.schema');
        $processes = [];
        $barrier = sys_get_temp_dir().'/svc-cadence-race-'.Str::lower(Str::random(16));
        $paths = [
            'first-ready' => $barrier.'-first-ready',
            'second-ready' => $barrier.'-second-ready',
            'start' => $barrier.'-start',
            'first-creating' => $barrier.'-first-creating',
            'second-creating' => $barrier.'-second-creating',
            'create' => $barrier.'-create',
        ];
        try {
            $user = User::factory()->create(['email' => 'cadence-race@synthetic.test']);
            $workspace = Workspace::query()->create(['name' => 'Synthetic cadence race', 'slug' => 'synthetic-cadence-race']);
            $workspace->memberships()->create(['user_id' => $user->id, 'role' => 'owner']);
            $company = ClientCompany::query()->create([
                'workspace_id' => $workspace->id, 'name' => 'Synthetic cadence client', 'slug' => 'synthetic-cadence-client',
            ]);
            $project = ClientProject::query()->create([
                'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic cadence project',
            ]);
            $agreement = app(AgreementWorkflow::class)->activate(ClientAgreement::query()->create([
                'workspace_id' => $workspace->id,
                'client_company_id' => $company->id,
                'title' => 'Synthetic monthly agreement',
                'status' => 'draft',
                'currency' => 'USD',
                'starts_on' => '2026-01-01',
                'retainer_minutes' => 120,
                'retainer_amount' => 30000,
                'catch_up_threshold_minutes' => 0,
                'hourly_rate_amount' => 12000,
                'billing_cadence' => 'monthly',
                'bill_overage_interim' => false,
                'rollover_months' => 0,
            ]));
            ClientTimeEntry::query()->create([
                'workspace_id' => $workspace->id,
                'client_company_id' => $company->id,
                'client_project_id' => $project->id,
                'user_id' => $user->id,
                'worked_on' => '2026-08-12',
                'minutes' => 90,
                'description' => 'Synthetic August work',
                'is_billable' => true,
                'is_deferred' => false,
                'status' => 'approved',
                'billing_rate_amount' => 12000,
                'billing_rate_source' => 'agreement',
                'currency' => 'USD',
            ]);
            $schedule = ClientBillingSchedule::query()->create([
                'workspace_id' => $workspace->id,
                'client_company_id' => $company->id,
                'client_agreement_id' => $agreement->id,
                'cadence' => 'monthly',
                'next_run_on' => '2026-08-01',
                'due_days' => 14,
                'currency' => 'USD',
                'line_template' => [[
                    'type' => 'adjustment', 'description' => 'Synthetic scheduled line',
                    'quantity' => '1.0000', 'unit_amount' => 5000, 'tax_amount' => 0, 'sort_order' => 0,
                ]],
            ]);

            $ids = ['workspace' => $workspace->id, 'company' => $company->id, 'agreement' => $agreement->id, 'schedule' => $schedule->id];
            $first = $this->worker($firstOperation, $ids, $paths['first-ready'], $paths['start'], $paths['first-creating'], $paths['create']);
            $second = $this->worker($secondOperation, $ids, $paths['second-ready'], $paths['start'], $paths['second-creating'], $paths['create']);
            $processes = [$first, $second];
            $first->start();
            $this->awaitFile($first, $paths['first-ready']);
            $this->assertTrue(touch($paths['start']), 'Could not start the first generator.');
            // The first generator is inside its transaction and past its guard
            // before the second is started, so the order the data provider
            // names is the order the locks are requested in.
            $this->awaitFile($first, $paths['first-creating']);
            $second->start();
            $this->awaitFile($second, $paths['second-ready']);
            $deadline = microtime(true) + self::SECOND_ARRIVAL_SECONDS;
            while (! is_file($paths['second-creating']) && $second->isRunning() && microtime(true) < $deadline) {
                usleep(10_000);
            }
            $bothReachedTheirInsert = is_file($paths['second-creating']);
            $this->assertTrue(touch($paths['create']), 'Could not release the generators.');
            $first->wait();
            $second->wait();
            $output = $first->getOutput().$first->getErrorOutput().$second->getOutput().$second->getErrorOutput();
            $this->assertFalse($bothReachedTheirInsert, 'Both generators got past their guards at once: '.$output);
            $results = [$this->workerResult($first), $this->workerResult($second)];
            $context = json_encode($results, JSON_THROW_ON_ERROR);

            DB::purge('cadence_race');
            $live = ClientInvoice::query()
                ->where('workspace_id', $workspace->id)
                ->where('client_agreement_id', $agreement->id)
                ->where('status', '!=', 'void')
                ->whereDate('service_period_start', '<=', '2026-08-31')
                ->whereDate('service_period_end', '>=', '2026-08-01')
                ->count();

            $this->assertSame(1, $live, 'August must be billed exactly once. '.$context);
            // The second may refuse, or - the schedule path - recognise the
            // agreement's invoice as the period already billed. Either is a
            // correct answer; writing a second invoice is not.
            $this->assertContains('success', array_column($results, 'outcome'), $context);
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

    /** @param  array<string, int>  $ids */
    private function worker(string $operation, array $ids, string $ready, string $start, string $creating, string $create): Process
    {
        $input = base64_encode(json_encode([
            'connection' => config('database.connections.'.DB::getDefaultConnection()),
            'operation' => $operation,
            'ready' => $ready,
            'start' => $start,
            'creating' => $creating,
            'create' => $create,
            ...$ids,
        ], JSON_THROW_ON_ERROR));

        return new Process(
            [PHP_BINARY, base_path('tests/Fixtures/Billing/cadence-generator-race-worker.php'), $input],
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
