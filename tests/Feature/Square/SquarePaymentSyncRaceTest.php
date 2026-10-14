<?php

namespace Tests\Feature\Square;

use App\Models\ClientInvoicePayment;
use App\Services\Square\SquarePaymentReconciler;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\FakesSquarePaymentSync;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

final class SquarePaymentSyncRaceTest extends TestCase
{
    use FakesSquarePaymentSync;
    use UsesAProbeDatabase;

    public function test_two_concurrent_syncs_record_one_payment(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('The two-process payment race runs in the MariaDB lane.');
        }
        $this->bootProbeDatabase('square_payment_race');
        Artisan::call('migrate', ['--database' => 'square_payment_race', '--force' => true]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('square_payment_race');
        Schema::clearResolvedInstance('db.schema');
        $prefix = sys_get_temp_dir().'/svc-square-payment-race-'.Str::lower(Str::random(16));
        $paths = [$prefix.'-first', $prefix.'-second', $prefix.'-release'];
        $processes = [];
        try {
            [$workspace, , , $invoice] = $this->paymentFixture();
            foreach (array_slice($paths, 0, 2) as $ready) {
                $payload = base64_encode(json_encode([
                    'connection' => config('database.connections.square_payment_race'),
                    'square' => config('services.square'), 'workspace' => $workspace->id,
                    'invoice' => $this->paymentInvoice, 'order' => $this->paymentOrder,
                    'payment' => $this->squarePayments['pay_fixture_1'], 'ready' => $ready, 'release' => $paths[2],
                ], JSON_THROW_ON_ERROR));
                $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/Square/payment-sync-race-worker.php'), $payload], base_path(), ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null', 'MAIL_MAILER' => 'array'], timeout: 60);
                $worker->start();
                $processes[] = $worker;
            }
            foreach ($processes as $index => $worker) {
                $deadline = microtime(true) + 20;
                while (! is_file($paths[$index]) && $worker->isRunning() && microtime(true) < $deadline) {
                    usleep(10_000);
                }
                $this->assertFileExists($paths[$index], $worker->getOutput().$worker->getErrorOutput());
            }
            $this->assertTrue(touch($paths[2]));
            foreach ($processes as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getOutput().$worker->getErrorOutput());
                $this->assertStringContainsString('completed', $worker->getOutput());
            }
            DB::purge('square_payment_race');
            $this->assertSame(1, ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->where('provider', 'square')->where('provider_payment_identifier', 'pay_fixture_1')->count());
            $this->assertSame('paid', $invoice->fresh()->status);
            $this->assertSame(12_345, $invoice->fresh()->paid_amount);
            // The real sync remains idempotent after both workers have committed.
            app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
            $this->assertSame(1, $invoice->payments()->count());
        } finally {
            foreach ($processes as $worker) {
                $worker->stop(0);
            }
            foreach ($paths as $path) {
                @unlink($path);
            }
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
        }
    }
}
