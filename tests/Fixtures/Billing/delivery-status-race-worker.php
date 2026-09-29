<?php

use App\Models\ClientInvoiceEmailDelivery;
use App\Services\Billing\InvoiceDeliveryStatusService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

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
$barrierPrefix = sys_get_temp_dir().DIRECTORY_SEPARATOR.'svc-status-race-';
foreach (['saving', 'save'] as $key) {
    if (! is_string($input[$key]) || ! str_starts_with($input[$key], $barrierPrefix)) {
        exit(2);
    }
}
config(['database.connections.status_race' => $input['connection'], 'database.default' => 'status_race']);
DB::purge('status_race');

// After the severity comparison and before the write: the point where two
// events that do not exclude each other have both decided to record.
ClientInvoiceEmailDelivery::saving(static function () use ($input): void {
    if (! touch($input['saving'])) {
        exit(3);
    }
    $deadline = microtime(true) + 20;
    while (! is_file($input['save']) && microtime(true) < $deadline) {
        usleep(10_000);
    }
    if (! is_file($input['save'])) {
        exit(3);
    }
});

$outcome = app(InvoiceDeliveryStatusService::class)->record([
    'event' => (string) $input['event'],
    'message-id' => (string) $input['reference'],
]);
echo json_encode(['outcome' => $outcome->value], JSON_THROW_ON_ERROR)."\n";
