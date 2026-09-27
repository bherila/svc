<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoiceEmailDelivery;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

/**
 * Two provider events for one message, in separate processes.
 *
 * `InvoiceDeliveryStatusService` ranks events rather than letting the last one
 * win, because a late `delivered` must not paper over the hard bounce that
 * followed it. Ranking only holds if the comparison and the write see the same
 * row: read unlocked, a bounce and a `delivered` in flight together both found
 * no status, both passed, and the second write won.
 *
 * The bounce is started first and held after its comparison, before its write.
 * The `delivered` event is then given time to reach the same point. Serialised,
 * it cannot - it is waiting for the row - and when it gets the row it reads the
 * bounce and stands down.
 */
final class InvoiceDeliveryStatusConcurrencyTest extends TestCase
{
    use UsesAProbeDatabase;

    private const SECOND_ARRIVAL_SECONDS = 3.0;

    public function test_a_late_delivered_event_cannot_overwrite_a_concurrent_hard_bounce(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Provider-event row-lock contention is exercised in the MariaDB lane.');
        }

        $this->bootProbeDatabase('status_race');
        Artisan::call('migrate', ['--database' => 'status_race', '--force' => true]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('status_race');
        Schema::clearResolvedInstance('db.schema');
        $processes = [];
        $barrier = sys_get_temp_dir().'/svc-status-race-'.Str::lower(Str::random(16));
        $paths = [
            'bounce-saving' => $barrier.'-bounce-saving',
            'bounce-save' => $barrier.'-bounce-save',
            'delivered-saving' => $barrier.'-delivered-saving',
            'delivered-save' => $barrier.'-delivered-save',
        ];
        try {
            $owner = User::factory()->create(['email' => 'status-race@synthetic.test']);
            $workspace = Workspace::query()->create(['name' => 'Synthetic status race', 'slug' => 'synthetic-status-race']);
            $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
            $company = ClientCompany::query()->create([
                'workspace_id' => $workspace->id, 'name' => 'Synthetic status client', 'slug' => 'synthetic-status-client',
            ]);
            $lifecycle = app(InvoiceLifecycleService::class);
            $invoice = $lifecycle->issue($lifecycle->createDraft($workspace, $company, [
                'invoice_number' => 'SYNTHETIC-STATUS-RACE',
                'currency' => 'USD',
            ], [[
                'type' => 'fee', 'description' => 'Synthetic status race service',
                'quantity' => '1', 'unit_amount' => 1000, 'tax_amount' => 0,
            ]]), $workspace);
            $delivery = ClientInvoiceEmailDelivery::query()->create([
                'workspace_id' => $workspace->id,
                'client_invoice_id' => $invoice->id,
                'recipients' => ['ap@synthetic.test'],
                'subject' => 'Invoice',
                'status' => 'sent',
                'provider_message_reference' => 'synthetic-status-race-message',
            ]);

            $bounce = $this->worker('hard_bounce', $paths['bounce-saving'], $paths['bounce-save']);
            $delivered = $this->worker('delivered', $paths['delivered-saving'], $paths['delivered-save']);
            $processes = [$bounce, $delivered];
            $bounce->start();
            $this->awaitFile($bounce, $paths['bounce-saving']);
            $delivered->start();
            $deadline = microtime(true) + self::SECOND_ARRIVAL_SECONDS;
            while (! is_file($paths['delivered-saving']) && $delivered->isRunning() && microtime(true) < $deadline) {
                usleep(10_000);
            }
            $bothDecidedToWrite = is_file($paths['delivered-saving']);

            // The bounce writes first, then the late event: the order that
            // leaves `delivered` on the row when nothing serialises them.
            $this->assertTrue(touch($paths['bounce-save']), 'Could not release the bounce.');
            $bounce->wait();
            $this->assertTrue(touch($paths['delivered-save']), 'Could not release the delivered event.');
            $delivered->wait();
            $output = $bounce->getOutput().$bounce->getErrorOutput().$delivered->getOutput().$delivered->getErrorOutput();

            $this->assertFalse($bothDecidedToWrite, 'Both events passed the ranking before either wrote: '.$output);
            $this->assertSame('recorded', $this->workerResult($bounce)['outcome'], $output);
            $this->assertSame('superseded', $this->workerResult($delivered)['outcome'], $output);
            DB::purge('status_race');
            $this->assertSame('hard_bounce', ClientInvoiceEmailDelivery::query()
                ->where('workspace_id', $workspace->id)
                ->findOrFail($delivery->id)
                ->provider_status);
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

    private function worker(string $event, string $saving, string $save): Process
    {
        $input = base64_encode(json_encode([
            'connection' => config('database.connections.'.DB::getDefaultConnection()),
            'event' => $event,
            'reference' => 'synthetic-status-race-message',
            'saving' => $saving,
            'save' => $save,
        ], JSON_THROW_ON_ERROR));

        return new Process(
            [PHP_BINARY, base_path('tests/Fixtures/Billing/delivery-status-race-worker.php'), $input],
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
