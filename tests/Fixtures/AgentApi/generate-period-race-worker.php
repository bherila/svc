<?php

use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentInvoiceOperations;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

// Synthetic two-process probe only. Connection settings arrive base64-encoded
// on argv and are never logged or persisted. The schema must have the probe
// prefix and every barrier the race prefix.
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
$barrierPrefix = sys_get_temp_dir().DIRECTORY_SEPARATOR.'svc-period-race-';
foreach (['ready', 'start', 'creating', 'create'] as $key) {
    if (! is_string($input[$key]) || ! str_starts_with($input[$key], $barrierPrefix)) {
        exit(2);
    }
}
config([
    'database.connections.period_race' => $input['connection'],
    'database.default' => 'period_race',
    'agent_api.writes_enabled' => true,
    'agent_api.invoice_writes_enabled' => true,
]);
DB::purge('period_race');

$await = static function (string $path): void {
    $deadline = microtime(true) + 20;
    while (! is_file($path) && microtime(true) < $deadline) {
        usleep(10_000);
    }
    if (! is_file($path)) {
        exit(3);
    }
};

// Past the period guard and before the draft's insert: the point where two
// calls that do not exclude each other have both decided to generate.
ClientInvoice::creating(static function () use ($input, $await): void {
    if (! touch($input['creating'])) {
        exit(3);
    }
    $await($input['create']);
});

$user = User::query()->findOrFail((int) $input['user']);
$workspace = Workspace::query()->findOrFail((int) $input['workspace']);

if (! touch($input['ready'])) {
    exit(3);
}
$await($input['start']);

try {
    $invoice = app(AgentInvoiceOperations::class)->generate($user, $workspace, 'synthetic-race-client', $input['key'], $input['agreement'], $input['body']);
    echo json_encode(['outcome' => 'success', 'id' => $invoice->public_id], JSON_THROW_ON_ERROR)."\n";
} catch (HttpExceptionInterface $exception) {
    echo json_encode(['outcome' => $exception->getStatusCode(), 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR)."\n";
} catch (ValidationException $exception) {
    echo json_encode(['outcome' => 422, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    // Exception messages may contain connection settings; expose only the class.
    echo json_encode(['outcome' => 'unexpected', 'class' => $exception::class], JSON_THROW_ON_ERROR)."\n";
    exit(1);
}
