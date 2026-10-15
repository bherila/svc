<?php

use App\Models\ClientInvoice;
use App\Services\Billing\Reminders\PaymentReminderService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

// Disposable probe only: synthetic rows, the array mailer, a scratch schema.
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$decoded = base64_decode((string) ($argv[1] ?? ''), true);
if ($decoded === false) {
    exit(2);
}
$input = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
if (! $app->environment('testing') || ! str_starts_with($input['connection']['database'], 'svc_probe_')) {
    exit(2);
}
foreach (['ready', 'release'] as $key) {
    if (! is_string($input[$key]) || ! str_starts_with($input[$key], sys_get_temp_dir().'/svc-payment-reminder-race-')) {
        exit(2);
    }
}
config(['database.connections.payment_reminder_race' => $input['connection'], 'database.default' => 'payment_reminder_race', 'mail.default' => 'array', 'app.url' => 'https://svc.example.test']);
DB::purge('payment_reminder_race');
Date::setTestNow($input['now']);
$invoice = ClientInvoice::query()->findOrFail($input['invoice']);
// Both runs have found the same due step before either claims it.
$temporary = $input['ready'].'.tmp';
if (file_put_contents($temporary, 'ready') === false || ! rename($temporary, $input['ready'])) {
    exit(3);
}
$deadline = microtime(true) + 25;
while (! is_file($input['release']) && microtime(true) < $deadline) {
    usleep(1_000);
}
if (! is_file($input['release'])) {
    exit(3);
}
try {
    $sent = app(PaymentReminderService::class)->sendAutomatic($invoice);
    echo json_encode(['outcome' => $sent === null ? 'skipped' : $sent->status->value], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    echo json_encode(['outcome' => 'unexpected', 'class' => $exception::class], JSON_THROW_ON_ERROR)."\n";
    exit(1);
}
