<?php

use App\Models\SquareSyncRun;
use App\Models\Workspace;
use App\Services\Square\Mirror\SquareMirrorSync;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

// Synthetic two-process probe only: every Square record is invented and served
// by Http::fake, so nothing reaches Square. Connection settings arrive
// base64-encoded on argv and are never logged or persisted. The schema must
// have the probe prefix and every barrier the race prefix.
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
$barrierPrefix = sys_get_temp_dir().DIRECTORY_SEPARATOR.'svc-square-mirror-race-';
foreach (['ready', 'start', 'fetched', 'write'] as $key) {
    if (! is_string($input[$key]) || ! str_starts_with($input[$key], $barrierPrefix)) {
        exit(2);
    }
}
config([
    'database.connections.mirror_race' => $input['connection'],
    'database.default' => 'mirror_race',
    'services.square' => $input['square'],
]);
DB::purge('mirror_race');
Sleep::fake();

$await = static function (string $path): void {
    $deadline = microtime(true) + 20;
    while (! is_file($path) && microtime(true) < $deadline) {
        usleep(10_000);
    }
    if (! is_file($path)) {
        exit(3);
    }
};

// Both workers have read the same invoices from "Square" before either writes
// one: the point where two syncs that do not exclude each other have both
// decided every invoice is new.
Http::fake(static function (Request $request) use ($input, $await) {
    $path = (string) parse_url($request->url(), PHP_URL_PATH);
    if ($path === '/v2/invoices') {
        if (! touch($input['fetched'])) {
            exit(3);
        }
        $await($input['write']);

        return Http::response(['invoices' => $input['invoices']]);
    }

    return match (true) {
        $path === '/v2/locations' => Http::response(['locations' => $input['locations']]),
        $path === '/v2/customers' => Http::response(['customers' => $input['customers']]),
        str_starts_with($path, '/v2/orders/') => Http::response(['order' => $input['order']]),
        default => Http::response(['errors' => [['category' => 'INVALID_REQUEST_ERROR', 'code' => 'NOT_FOUND']]], 404),
    };
});

$workspace = Workspace::query()->findOrFail((int) $input['workspace']);

if (! touch($input['ready'])) {
    exit(3);
}
$await($input['start']);

try {
    $sync = app(SquareMirrorSync::class);
    $run = $sync->perform($workspace, $sync->request($workspace, SquareSyncRun::TRIGGER_SCHEDULE));
    echo json_encode(['outcome' => $run->status, 'error' => $run->error_code, 'changed' => $run->invoices_changed], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    // Exception messages may contain connection settings; expose only the class.
    echo json_encode(['outcome' => 'unexpected', 'class' => $exception::class], JSON_THROW_ON_ERROR)."\n";
    exit(1);
}
