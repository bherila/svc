<?php

namespace Tests\Feature\Billing\Reminders;

use App\Models\InvoiceReminderLog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsPaymentReminders;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

/**
 * Two scheduler runs reach the same due step at once (#438): exactly one
 * claims and sends it. Real row locks need real processes on MariaDB.
 */
final class PaymentReminderRaceTest extends TestCase
{
    use BuildsPaymentReminders;
    use UsesAProbeDatabase;

    public function test_two_concurrent_cadence_runs_send_one_reminder(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('The two-process reminder race runs in the MariaDB lane.');
        }
        $this->bootProbeDatabase('payment_reminder_race');
        Artisan::call('migrate', ['--database' => 'payment_reminder_race', '--force' => true]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('payment_reminder_race');
        Schema::clearResolvedInstance('db.schema');
        $prefix = sys_get_temp_dir().'/svc-payment-reminder-race-'.Str::lower(Str::random(16));
        $paths = [$prefix.'-first', $prefix.'-second', $prefix.'-release'];
        $processes = [];
        $now = '2030-03-15 14:00:00';
        try {
            [$workspace, , , $invoice] = $this->reminderFixture(cadence: true);
            foreach (array_slice($paths, 0, 2) as $ready) {
                $payload = base64_encode(json_encode([
                    'connection' => config('database.connections.payment_reminder_race'),
                    'invoice' => $invoice->id, 'now' => $now, 'ready' => $ready, 'release' => $paths[2],
                ], JSON_THROW_ON_ERROR));
                $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/Billing/payment-reminder-race-worker.php'), $payload], base_path(), ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null', 'MAIL_MAILER' => 'array'], timeout: 60);
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
            $outcomes = [];
            foreach ($processes as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getOutput().$worker->getErrorOutput());
                $outcomes[] = json_decode(trim($worker->getOutput()), true, flags: JSON_THROW_ON_ERROR)['outcome'];
            }
            sort($outcomes);
            $this->assertSame(['sent', 'skipped'], $outcomes);
            DB::purge('payment_reminder_race');
            $rows = InvoiceReminderLog::query()->where('workspace_id', $workspace->id)->get();
            $this->assertCount(1, $rows);
            $this->assertSame('due', $rows->sole()->cadence_step?->value);
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
