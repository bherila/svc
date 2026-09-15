<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceEmailDelivery;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

final class InvoiceDeliveryConcurrencyTest extends TestCase
{
    use UsesAProbeDatabase;

    /** @return iterable<string, array{string, string}> */
    public static function competitors(): iterable
    {
        yield 'two scheduler workers' => ['automatic', 'automatic'];
        yield 'manual send and scheduler' => ['manual', 'automatic'];
        yield 'correction and scheduler' => ['correct', 'automatic'];
        yield 'void and scheduler' => ['void', 'automatic'];
        yield 'hold and scheduler' => ['hold', 'automatic'];
    }

    #[DataProvider('competitors')]
    public function test_separate_processes_cannot_accept_competing_delivery_effects(string $firstOperation, string $secondOperation): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Invoice delivery row-lock contention is exercised in the MariaDB lane.');
        }

        $this->bootProbeDatabase('delivery_race');
        Artisan::call('migrate', ['--database' => 'delivery_race', '--force' => true]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('delivery_race');
        Schema::clearResolvedInstance('db.schema');
        $processes = [];
        try {
            $owner = User::factory()->create(['email' => 'race-owner@synthetic.test']);
            $workspace = Workspace::query()->create(['name' => 'Synthetic delivery race', 'slug' => 'synthetic-delivery-race']);
            $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
            $company = ClientCompany::query()->create([
                'workspace_id' => $workspace->id,
                'name' => 'Synthetic race client',
                'slug' => 'synthetic-race-client',
                'billing_email' => 'billing@synthetic.test',
                'automatic_invoice_email_enabled' => true,
                'automatic_invoice_email_delay_days' => 0,
            ]);
            $invoice = app(InvoiceLifecycleService::class)->createDraft($workspace, $company, [
                'invoice_number' => 'SYNTHETIC-RACE',
                'currency' => 'USD',
            ], [[
                'type' => 'fee',
                'description' => 'Synthetic race service',
                'quantity' => '1',
                'unit_amount' => 1000,
                'tax_amount' => 0,
            ]]);
            $invoice = app(InvoiceLifecycleService::class)->issue($invoice, $workspace);

            [$first, $firstInput] = $this->worker($firstOperation, $workspace, $invoice);
            [$second, $secondInput] = $this->worker($secondOperation, $workspace, $invoice);
            $processes = [$first, $second];
            $first->start();
            $second->start();
            $this->assertTrue($first->waitUntil(fn (): bool => str_contains($first->getOutput(), "ready\n")), $first->getOutput());
            $this->assertTrue($second->waitUntil(fn (): bool => str_contains($second->getOutput(), "ready\n")), $second->getOutput());
            $firstInput->write("go\n");
            $secondInput->write("go\n");
            $firstInput->close();
            $secondInput->close();
            $first->wait();
            $second->wait();
            $firstResult = $this->workerResult($first);
            $secondResult = $this->workerResult($second);

            DB::purge('delivery_race');
            $invoice = ClientInvoice::query()->where('workspace_id', $workspace->id)->findOrFail($invoice->id);
            $sent = ClientInvoiceEmailDelivery::query()
                ->where('workspace_id', $workspace->id)
                ->where('client_invoice_id', $invoice->id)
                ->where('status', 'sent')
                ->count();
            $this->assertLessThanOrEqual(1, $sent, json_encode([$firstResult, $secondResult], JSON_THROW_ON_ERROR));

            if ($firstOperation === 'automatic') {
                $this->assertSame(1, $sent);
            }
            if ($firstOperation === 'correct') {
                $this->assertTrue(
                    ($invoice->document_revision === 2 && $invoice->automatic_delivery_status === 'held' && $sent === 0)
                    || ($invoice->document_revision === 1 && $invoice->automatic_delivery_status === 'automatically_sent' && $sent === 1),
                );
            }
            if ($firstOperation === 'hold') {
                $this->assertTrue(
                    ($invoice->automatic_delivery_status === 'held' && $sent === 0)
                    || ($invoice->automatic_delivery_status === 'automatically_sent' && $sent === 1),
                );
            }
            if ($firstOperation === 'void' && $sent === 0) {
                $this->assertSame('void', $invoice->status);
            }
        } finally {
            foreach ($processes as $process) {
                $process->stop(0);
            }
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
        }
    }

    /** @return array{0:Process,1:InputStream} */
    private function worker(string $operation, Workspace $workspace, ClientInvoice $invoice): array
    {
        $input = new InputStream;
        $input->write(json_encode([
            'connection' => config('database.connections.delivery_race'),
            'workspace' => $workspace->id,
            'invoice' => $invoice->id,
            'operation' => $operation,
        ], JSON_THROW_ON_ERROR)."\n");

        return [
            new Process(
                [PHP_BINARY, base_path('tests/Fixtures/Billing/invoice-delivery-race-worker.php')],
                base_path(),
                ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null', 'MAIL_MAILER' => 'array'],
                $input,
                60,
            ),
            $input,
        ];
    }

    /** @return array<string, mixed> */
    private function workerResult(Process $process): array
    {
        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        $lines = explode("\n", trim($process->getOutput()));

        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }
}
