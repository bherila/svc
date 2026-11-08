<?php

namespace Tests\Feature\Approval;

use App\Exceptions\DisputedRowsOnDraft;
use App\Models\ClientCompany;
use App\Models\ClientExpense;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
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
 * A client's dispute and the issue of the draft that charges the disputed row,
 * in two MariaDB sessions (docs/ux-overhaul.md 7b″).
 *
 * The issue guard reads the draft's time and expenses with row locks and holds
 * them until it commits. So either the dispute commits first and the issue is
 * refused - even for a caller whose transaction read something before the
 * dispute, whose ordinary reads would still show the row undisputed - or the
 * dispute waits for the charge, and the charge stands as any charged row's
 * does. What must not happen is the dispute committing while the issue is
 * past its guard, and the disputed row being charged anyway.
 *
 * The dispute here is the stamp under ClientApprovals::dispose()'s own locks,
 * without the draft rebuild that follows it: the guard exists for the rebuild
 * that did not happen.
 */
final class DisputeIssueRaceTest extends TestCase
{
    use UsesAProbeDatabase;

    /** Long enough for an unblocked dispute to have committed. */
    private const DISPUTER_SECONDS = 3.0;

    /** @return iterable<string, array{string}> */
    public static function rows(): iterable
    {
        yield 'an hour' => ['time'];
        yield 'an expense' => ['expense'];
    }

    #[DataProvider('rows')]
    public function test_a_dispute_committed_after_the_callers_snapshot_refuses_the_issue(string $row): void
    {
        $this->race($row, 'outer-snapshot', function (array $issuer, array $disputer, bool $disputeCommittedWhileHeld, ClientInvoice $draft, ClientTimeEntry|ClientExpense $disputed, string $context): void {
            $this->assertTrue($disputeCommittedWhileHeld, 'Nothing was locked yet, so the dispute commits. '.$context);
            $this->assertSame('success', $disputer['outcome'], $context);
            $this->assertSame('refused', $issuer['outcome'], $context);
            $this->assertSame(DisputedRowsOnDraft::class, $issuer['class'], $context);
            $this->assertSame('draft', $draft->fresh()?->status, $context);
            $this->assertNotNull($disputed->fresh()?->getRawOriginal('client_disputed_at'), $context);
        });
    }

    #[DataProvider('rows')]
    public function test_a_dispute_reaching_a_row_the_issue_has_locked_waits_for_the_charge(string $row): void
    {
        $this->race($row, 'held-after-guard', function (array $issuer, array $disputer, bool $disputeCommittedWhileHeld, ClientInvoice $draft, ClientTimeEntry|ClientExpense $disputed, string $context): void {
            $this->assertFalse($disputeCommittedWhileHeld, 'The dispute must wait for the issue that locked its row. '.$context);
            $this->assertSame(['outcome' => 'success', 'status' => 'issued'], $issuer, $context);
            $this->assertSame('success', $disputer['outcome'], $context);
            $this->assertSame('issued', $draft->fresh()?->status, $context);
            // The charge stands, as any charged row's does until a correction.
            $this->assertNotNull($disputed->fresh()?->getRawOriginal('client_disputed_at'), $context);
        });
    }

    /** @param callable(array<string, mixed>, array<string, mixed>, bool, ClientInvoice, ClientTimeEntry|ClientExpense, string): void $assert */
    private function race(string $row, string $mode, callable $assert): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Row locks across two sessions are exercised in the MariaDB lane.');
        }

        $this->bootProbeDatabase('dispute_race');
        Artisan::call('migrate', ['--database' => 'dispute_race', '--force' => true]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('dispute_race');
        Schema::clearResolvedInstance('db.schema');
        $processes = [];
        $barrier = sys_get_temp_dir().'/svc-dispute-race-'.Str::lower(Str::random(16));
        $paused = $barrier.'-paused';
        $release = $barrier.'-release';
        try {
            [$workspace, $draft, $entry, $expense] = $this->draftChargingAnHourAndAnExpense($row.'-'.$mode);
            $disputed = $row === 'time' ? $entry : $expense;

            $issuer = $this->worker($workspace, ['mode' => $mode, 'invoice' => $draft->id, 'paused' => $paused, 'release' => $release]);
            $disputer = $this->worker($workspace, ['mode' => 'dispute', 'row' => $row, 'row_id' => $disputed->id, 'paused' => null, 'release' => null]);
            $processes = [$issuer, $disputer];
            $issuer->start();
            $this->awaitFile($issuer, $paused);
            $disputer->start();
            $deadline = microtime(true) + self::DISPUTER_SECONDS;
            while ($disputer->isRunning() && microtime(true) < $deadline) {
                usleep(10_000);
            }
            $disputeCommittedWhileHeld = ! $disputer->isRunning();
            $this->assertTrue(touch($release), 'Could not release the held issue.');
            $issuer->wait();
            $disputer->wait();
            $context = $issuer->getOutput().$issuer->getErrorOutput().$disputer->getOutput().$disputer->getErrorOutput();

            DB::purge('dispute_race');
            $assert($this->workerResult($issuer), $this->workerResult($disputer), $disputeCommittedWhileHeld, $draft, $disputed, $context);
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

    /** @return array{Workspace, ClientInvoice, ClientTimeEntry, ClientExpense} */
    private function draftChargingAnHourAndAnExpense(string $name): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic dispute race '.$name, 'slug' => 'synthetic-dispute-race-'.$name]);
        $consultant = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $consultant->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id, 'name' => 'Synthetic dispute race client', 'slug' => 'synthetic-dispute-race-client-'.$name,
        ]);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic race project']);
        $entry = ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id,
            'user_id' => $consultant->id, 'worked_on' => '2026-09-10', 'minutes' => 120, 'description' => 'Synthetic race work',
            'status' => 'approved', 'is_billable' => true, 'is_deferred' => false,
        ]);
        $draft = ClientInvoice::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'invoice_number' => 'SYNTH-DISPUTE-RACE',
            'currency' => 'USD', 'status' => 'draft', 'invoice_kind' => 'ad_hoc',
            'subtotal_amount' => 24200, 'tax_amount' => 0, 'total_amount' => 24200,
        ]);
        $hours = $draft->lines()->create([
            'workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'type' => 'additional_hours',
            'description' => 'Synthetic hours', 'quantity' => 2, 'unit_amount' => 10000, 'tax_amount' => 0, 'total_amount' => 20000, 'sort_order' => 1,
        ]);
        $hours->timeEntries()->attach($entry->id, ['workspace_id' => $workspace->id]);
        $expenseLine = $draft->lines()->create([
            'workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'type' => 'expense',
            'description' => 'Synthetic expense', 'quantity' => 1, 'unit_amount' => 4200, 'tax_amount' => 0, 'total_amount' => 4200, 'sort_order' => 2,
        ]);
        $expense = ClientExpense::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id,
            'created_by_user_id' => $consultant->id, 'spent_on' => '2026-09-12', 'amount' => 4200, 'currency' => 'USD',
            'description' => 'Synthetic expense', 'status' => 'approved', 'client_invoice_line_id' => $expenseLine->id,
        ]);

        return [$workspace, $draft, $entry->refresh(), $expense->refresh()];
    }

    /** @param array<string, mixed> $input */
    private function worker(Workspace $workspace, array $input): Process
    {
        $payload = base64_encode(json_encode($input + [
            'connection' => config('database.connections.'.DB::getDefaultConnection()),
            'workspace' => $workspace->id,
        ], JSON_THROW_ON_ERROR));

        return new Process(
            [PHP_BINARY, base_path('tests/Fixtures/Approval/dispute-issue-race-worker.php'), $payload],
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
