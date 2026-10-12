<?php

namespace Tests\Feature\Square;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\InvoiceSquareLink;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

/**
 * Two MariaDB processes link two different SVC invoices to the same Square
 * invoice at once (#438).
 *
 * Each locks only its own invoice row, so the row locks never meet: both are
 * held after checking that the Square invoice is free and before inserting,
 * then released together. The unique index over the active Square slot is all
 * that stands between them, and exactly one link may survive - the other
 * caller gets the same 409 a sequential second link gets.
 */
final class SquareLinkRaceTest extends TestCase
{
    use UsesAProbeDatabase;

    public function test_two_concurrent_links_of_one_square_invoice_leave_one_active_link(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Concurrent unique-key insertion is exercised in the MariaDB lane.');
        }

        $this->bootProbeDatabase('square_race');
        Artisan::call('migrate', ['--database' => 'square_race', '--force' => true]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('square_race');
        Schema::clearResolvedInstance('db.schema');
        $processes = [];
        $barriers = [];
        try {
            $owner = User::factory()->create();
            $workspace = Workspace::query()->create(['name' => 'Synthetic Square race', 'slug' => 'synthetic-square-race']);
            $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
            $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic race client', 'slug' => 'synthetic-race-client']);
            $invoices = [$this->invoice($company, 'SYN-RACE-1'), $this->invoice($company, 'SYN-RACE-2')];

            foreach ($invoices as $invoice) {
                $barrier = sys_get_temp_dir().'/svc-square-link-race-'.Str::lower(Str::random(16));
                $barriers[] = [$barrier.'-paused', $barrier.'-release'];
                $processes[] = $this->worker($workspace, $owner, $invoice, $barrier.'-paused', $barrier.'-release');
            }
            foreach ($processes as $index => $process) {
                $process->start();
            }
            foreach ($processes as $index => $process) {
                $this->awaitFile($process, $barriers[$index][0]);
            }
            foreach ($barriers as [, $release]) {
                $this->assertTrue(touch($release), 'Could not release a held link.');
            }
            foreach ($processes as $process) {
                $process->wait();
            }
            $output = implode(' ', array_map(static fn (Process $process): string => $process->getOutput().$process->getErrorOutput(), $processes));
            $results = array_map($this->workerResult(...), $processes);
            $outcomes = array_column($results, 'outcome');
            sort($outcomes);

            DB::purge('square_race');
            $this->assertSame(['refused', 'success'], $outcomes, $output);
            $refused = array_values(array_filter($results, static fn (array $result): bool => $result['outcome'] === 'refused'));
            $this->assertSame([409], array_column($refused, 'status'), $output);
            $this->assertSame(1, InvoiceSquareLink::query()->where('workspace_id', $workspace->id)->where('square_invoice_id', 'inv:0-fixturerace')->count(), $output);
            $this->assertSame(1, InvoiceSquareLink::query()->where('workspace_id', $workspace->id)->where('active_square_invoice_slot', 'inv:0-fixturerace')->count(), $output);
            $versions = ClientInvoice::query()->where('workspace_id', $workspace->id)->orderBy('id')->pluck('lock_version')->map(static fn (mixed $version): int => (int) $version)->all();
            sort($versions);
            $this->assertSame([1, 2], $versions, 'Only the winner advanced its invoice. '.$output);
        } finally {
            foreach ($processes as $process) {
                $process->stop(0);
            }
            foreach ($barriers as [$paused, $release]) {
                @unlink($paused);
                @unlink($release);
            }
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
        }
    }

    private function invoice(ClientCompany $company, string $number): ClientInvoice
    {
        return ClientInvoice::query()->create(['workspace_id' => $company->workspace_id, 'client_company_id' => $company->id, 'invoice_number' => $number, 'invoice_kind' => 'ad_hoc', 'status' => 'issued', 'currency' => 'USD', 'subtotal_amount' => 5000, 'tax_amount' => 0, 'total_amount' => 5000, 'paid_amount' => 0, 'balance_amount' => 5000]);
    }

    private function worker(Workspace $workspace, User $owner, ClientInvoice $invoice, string $paused, string $release): Process
    {
        $input = base64_encode(json_encode([
            'connection' => config('database.connections.'.DB::getDefaultConnection()),
            'workspace' => $workspace->id,
            'invoice' => $invoice->id,
            'user' => $owner->id,
            'paused' => $paused,
            'release' => $release,
        ], JSON_THROW_ON_ERROR));

        return new Process(
            [PHP_BINARY, base_path('tests/Fixtures/Square/link-race-worker.php'), $input],
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
