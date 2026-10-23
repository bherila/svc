<?php

namespace Tests\Feature\Square;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\InvoiceSquareLink;
use App\Models\SquareInvoiceCoverage;
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
 * Two MariaDB processes claim the same Square invoice for two different SVC
 * invoices at once (#438, #487): two links, or a link and a coverage.
 *
 * Each locks only its own invoice row, so those locks never meet. Links and
 * coverages live in two tables whose unique indexes cannot see each other, so
 * every claim takes the workspace row next and reads the claim tables only
 * then. The first claim is held at that read; the second must not reach it
 * until the first commits, and then gets the same 409 a sequential second
 * claim gets. Exactly one claim survives.
 */
final class SquareLinkRaceTest extends TestCase
{
    use UsesAProbeDatabase;

    public function test_two_concurrent_links_of_one_square_invoice_leave_one_active_link(): void
    {
        $this->race('link');
    }

    public function test_a_concurrent_link_and_coverage_of_one_square_invoice_leave_one_claim(): void
    {
        $this->race('cover');
    }

    /** @param  'link'|'cover'  $first  what the first invoice claims; the second always links */
    private function race(string $first): void
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
            if ($first === 'cover') {
                // A coverage stands beside its invoice's own primary link.
                InvoiceSquareLink::query()->create(['workspace_id' => $workspace->id, 'client_invoice_id' => $invoices[0]->id, 'square_invoice_id' => 'inv:0-fixtureprimary', 'square_invoice_number' => '000001-R-0002', 'series_key' => '000001', 'currency' => 'USD', 'match_method' => 'manual', 'active_invoice_slot' => $invoices[0]->id, 'active_square_invoice_slot' => 'inv:0-fixtureprimary', 'linked_at' => now()]);
            }

            foreach ($invoices as $index => $invoice) {
                $barrier = sys_get_temp_dir().'/svc-square-link-race-'.Str::lower(Str::random(16));
                $barriers[] = [$barrier.'-paused', $barrier.'-release'];
                $processes[] = $this->worker($workspace, $owner, $invoice, $barrier.'-paused', $barrier.'-release', $index === 0 ? $first : 'link');
            }
            // The first claim is held inside its transaction, past the workspace lock.
            $processes[0]->start();
            $this->awaitFile($processes[0], $barriers[0][0]);
            $processes[1]->start();
            // The second waits on the workspace row: it cannot read the claim tables yet.
            $deadline = microtime(true) + 3;
            while (microtime(true) < $deadline && $processes[1]->isRunning()) {
                usleep(50_000);
            }
            $this->assertFileDoesNotExist($barriers[1][0], 'The second claim read the claim tables while the first was uncommitted. '.$processes[1]->getOutput().$processes[1]->getErrorOutput());
            foreach ($barriers as [, $release]) {
                $this->assertTrue(touch($release), 'Could not release a held claim.');
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
            $claims = InvoiceSquareLink::query()->where('workspace_id', $workspace->id)->where('active_square_invoice_slot', 'inv:0-fixturerace')->count()
                + SquareInvoiceCoverage::query()->where('workspace_id', $workspace->id)->where('active_square_invoice_slot', 'inv:0-fixturerace')->count();
            $this->assertSame(1, $claims, $output);
            $this->assertSame($first === 'cover' ? 1 : 0, SquareInvoiceCoverage::query()->where('workspace_id', $workspace->id)->where('square_invoice_id', 'inv:0-fixturerace')->count(), 'The first claim won. '.$output);
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

    private function worker(Workspace $workspace, User $owner, ClientInvoice $invoice, string $paused, string $release, string $action): Process
    {
        $input = base64_encode(json_encode([
            'action' => $action,
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
