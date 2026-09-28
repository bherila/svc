<?php

use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\Billing\InterimClaimRefused;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
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
$barrierPrefix = sys_get_temp_dir().DIRECTORY_SEPARATOR.'svc-interim-race-';
foreach (['paused', 'release'] as $key) {
    if ($input[$key] !== null && (! is_string($input[$key]) || ! str_starts_with($input[$key], $barrierPrefix))) {
        exit(2);
    }
}
config(['database.connections.interim_race' => $input['connection'], 'database.default' => 'interim_race']);
DB::purge('interim_race');

$workspace = Workspace::query()->whereKey((int) $input['workspace'])->firstOrFail();

// A committed time change, as any time writer would make one.
if (($input['mode'] ?? 'issue') === 'reduce-time') {
    DB::table('client_time_entries')->where('workspace_id', $workspace->id)->where('id', (int) $input['entry'])->update(['minutes' => (int) $input['minutes']]);
    echo json_encode(['outcome' => 'success'], JSON_THROW_ON_ERROR)."\n";
    exit(0);
}

$invoice = ClientInvoice::query()->where('workspace_id', $workspace->id)->whereKey((int) $input['invoice'])->firstOrFail();

$hold = static function () use ($input): void {
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
};

// Held just after issue() has locked the invoice - with the agreement already
// locked ahead of it where issue() takes one.
if ($input['paused'] !== null && ($input['mode'] ?? 'issue') === 'issue') {
    $armed = true;
    DB::listen(static function (QueryExecuted $query) use (&$armed, $input): void {
        if (! $armed
            || DB::transactionLevel() < 1
            || ! str_contains($query->sql, 'from `client_invoices`')
            || ! str_contains(strtolower($query->sql), 'for update')) {
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
}

try {
    $issue = static fn (): ClientInvoice => app(InvoiceLifecycleService::class)->issue($invoice, $workspace);
    // A caller whose own transaction has already read something - the agent
    // API's receipt transaction - so the snapshot predates issue().
    $issued = ($input['mode'] ?? 'issue') === 'outer-snapshot'
        ? DB::transaction(static function () use ($issue, $hold, $workspace): ClientInvoice {
            DB::table('client_time_entries')->where('workspace_id', $workspace->id)->count();
            $hold();

            return $issue();
        })
        : $issue();
    echo json_encode(['outcome' => 'success', 'hours' => (string) $issued->hours_billed_at_rate], JSON_THROW_ON_ERROR)."\n";
} catch (InterimClaimRefused $refusal) {
    echo json_encode(['outcome' => 'refused', 'regenerate' => $refusal->regenerate], JSON_THROW_ON_ERROR)."\n";
} catch (DomainException $refusal) {
    echo json_encode(['outcome' => 'refused', 'class' => $refusal::class], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    echo json_encode(['outcome' => 'unexpected', 'class' => $exception::class, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR)."\n";
    exit(1);
}
