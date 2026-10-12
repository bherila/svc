<?php

use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Square\SquareLinkService;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Square\SquareInvoiceReference;
use App\Support\Square\SquareMatchMethod;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
$barrierPrefix = sys_get_temp_dir().DIRECTORY_SEPARATOR.'svc-square-link-race-';
foreach (['paused', 'release'] as $key) {
    if (! is_string($input[$key]) || ! str_starts_with($input[$key], $barrierPrefix)) {
        exit(2);
    }
}
config(['database.connections.square_race' => $input['connection'], 'database.default' => 'square_race']);
DB::purge('square_race');

// Hold the link just after it has checked that the Square invoice is free and
// before it inserts: both workers then believe they may take it.
$armed = true;
DB::listen(static function (QueryExecuted $query) use (&$armed, $input): void {
    if (! $armed || ! str_contains($query->sql, 'from `invoice_square_links`') || ! str_contains($query->sql, 'active_square_invoice_slot')) {
        return;
    }
    $armed = false;
    if (! touch($input['paused'])) {
        exit(3);
    }
    $deadline = microtime(true) + 30;
    while (! is_file($input['release']) && microtime(true) < $deadline) {
        usleep(10_000);
    }
    if (! is_file($input['release'])) {
        exit(3);
    }
});

$workspace = Workspace::query()->whereKey((int) $input['workspace'])->firstOrFail();
$invoice = ClientInvoice::query()->where('workspace_id', $workspace->id)->whereKey((int) $input['invoice'])->firstOrFail();
$actor = User::query()->whereKey((int) $input['user'])->firstOrFail();

try {
    $link = app(SquareLinkService::class)->link($workspace, $invoice, $actor, AgentApiVersion::for($invoice), new SquareInvoiceReference(
        'inv:0-fixturerace', '000001-R-0001', 'USD', SquareMatchMethod::Manual,
    ), false);
    echo json_encode(['outcome' => 'success', 'link' => $link->public_id], JSON_THROW_ON_ERROR)."\n";
} catch (HttpExceptionInterface $exception) {
    echo json_encode(['outcome' => 'refused', 'status' => $exception->getStatusCode(), 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    echo json_encode(['outcome' => 'unexpected', 'class' => $exception::class, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR)."\n";
    exit(1);
}
