<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoiceEmailDelivery;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

/**
 * Two manual sends from one workspace at once, for different invoices, in two
 * MariaDB sessions shaped like the agent API's: each has share-locked the
 * workspace row (the receipt insert's foreign key check) and taken its
 * snapshot before either sends.
 *
 * The second waits on the first's budget key rather than deadlocking on the
 * workspace row. Once the first commits, the second counts its delivery
 * through a locking read, which sees it whatever the second's snapshot: refused
 * when both would exceed the limit, admitted when both fit exactly - counted
 * once, not twice. Both at REPEATABLE READ and READ COMMITTED
 * (billing-simplification-plan.md, Part 1).
 */
final class ManualClientEmailBudgetConcurrencyTest extends TestCase
{
    use UsesAProbeDatabase;

    /** @return iterable<string, array{string, int, bool}> */
    public static function levels(): iterable
    {
        foreach (['REPEATABLE READ', 'READ COMMITTED'] as $isolation) {
            // Two recipients each: four do not fit under three.
            yield "over the limit, {$isolation}" => [$isolation, 3, false];
            // Four fit exactly under four; counting the first twice would refuse.
            yield "exactly at the limit, {$isolation}" => [$isolation, 4, true];
        }
    }

    #[DataProvider('levels')]
    public function test_a_second_send_waits_on_the_budget_key_and_counts_the_first_once(string $isolation, int $limit, bool $admitted): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Row-lock contention is exercised in the MariaDB lane.');
        }
        $this->bootProbeDatabase('client_email_budget_probe');
        $original = config('database.default');
        Artisan::call('migrate', ['--database' => 'client_email_budget_probe', '--force' => true]);
        config(['database.default' => 'client_email_budget_probe', 'mail.default' => 'array']);
        $processes = [];
        try {
            $owner = User::factory()->create(['email' => 'budget-race-owner@synthetic.test']);
            $workspace = Workspace::query()->create(['name' => 'Synthetic budget race', 'slug' => 'synthetic-budget-race']);
            $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
            $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic budget race client', 'slug' => 'synthetic-budget-race-client']);
            $service = app(InvoiceLifecycleService::class);
            $invoices = [];
            foreach (['A', 'B'] as $suffix) {
                $invoices[] = $service->issue($service->createDraft($workspace, $company, [
                    'currency' => 'USD',
                    'invoice_number' => 'SYNTHETIC-BUDGET-'.$suffix,
                ], [[
                    'type' => 'adjustment',
                    'description' => 'Synthetic budget race service',
                    'quantity' => 1,
                    'unit_amount' => 100,
                ]]), $workspace);
            }
            $configuration = ['connection' => ['isolation_level' => $isolation] + config('database.connections.client_email_budget_probe'), 'workspace' => $workspace->id, 'limit' => $limit];

            // The second send's snapshot is taken first, before anything is sent.
            $secondInput = new InputStream;
            $secondInput->write(json_encode($configuration + ['invoice' => $invoices[1]->id, 'recipients' => ['three@budget-race.example.test', 'four@budget-race.example.test'], 'hold' => false, 'await' => true], JSON_THROW_ON_ERROR)."\n");
            $second = $this->worker($secondInput);
            $processes[] = $second;
            $second->start();
            $this->assertTrue($second->waitUntil(fn (): bool => str_contains($second->getOutput(), "snapshot\n")), $second->getOutput());

            $firstInput = new InputStream;
            $firstInput->write(json_encode($configuration + ['invoice' => $invoices[0]->id, 'recipients' => ['one@budget-race.example.test', 'two@budget-race.example.test'], 'hold' => true, 'await' => false], JSON_THROW_ON_ERROR)."\n");
            $first = $this->worker($firstInput);
            $processes[] = $first;
            $first->start();
            $this->assertTrue($first->waitUntil(fn (): bool => str_contains($first->getOutput(), "admitted\n")), $first->getOutput());

            $secondInput->write("go\n");
            $secondInput->close();
            $this->assertTrue($second->waitUntil(fn (): bool => str_contains($second->getOutput(), "attempting-lock\n")), $second->getOutput());
            if (preg_match('/connection:(\d+)/', $second->getOutput(), $match) !== 1) {
                $this->fail('The second worker did not report its connection: '.$second->getOutput());
            }
            $connectionId = (int) $match[1];
            $waiting = false;
            $deadline = microtime(true) + 10;
            do {
                $transaction = DB::selectOne('select trx_state from information_schema.innodb_trx where trx_mysql_thread_id = ?', [$connectionId]);
                $waiting = $transaction?->trx_state === 'LOCK WAIT';
                if (! $waiting) {
                    // MariaDB refreshes this view only after 100ms without a read.
                    usleep(1_000_000);
                }
            } while (! $waiting && $second->isRunning() && microtime(true) < $deadline);
            $this->assertTrue($waiting, 'The second send must wait on the first one\'s budget key, not deadlock. '.$second->getOutput());

            $firstInput->write("release\n");
            $firstInput->close();
            $first->wait();
            $second->wait();
            $this->assertTrue($first->isSuccessful(), $first->getOutput());
            $this->assertTrue($second->isSuccessful(), $second->getOutput());
            $level = 'isolation:'.str_replace(' ', '-', $isolation)."\n";
            $this->assertStringContainsString($level, $first->getOutput());
            $this->assertStringContainsString($level, $second->getOutput());
            $this->assertStringEndsWith("sent\n", $first->getOutput());
            if ($admitted) {
                $this->assertStringEndsWith("sent\n", $second->getOutput());
            } else {
                $this->assertStringContainsString("refused: This workspace has reached its limit of {$limit} recipients", $second->getOutput());
            }

            DB::purge('client_email_budget_probe');
            $this->assertSame($admitted ? 2 : 1, ClientInvoiceEmailDelivery::query()->where('workspace_id', $workspace->id)->where('origin', 'manual')->count());
            $this->assertSame($admitted ? 4 : 2, (int) DB::table('client_email_budget_locks')->where('workspace_id', $workspace->id)->value('admitted_recipients'));
        } finally {
            foreach ($processes as $process) {
                $process->stop(0);
            }
            config(['database.default' => $original]);
        }
    }

    private function worker(InputStream $input): Process
    {
        return new Process([PHP_BINARY, base_path('tests/Fixtures/Billing/client-email-budget-worker.php')], base_path(), ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null'], $input, 30);
    }
}
