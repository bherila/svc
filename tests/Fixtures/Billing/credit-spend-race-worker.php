<?php

use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
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
$barrierPrefix = sys_get_temp_dir().DIRECTORY_SEPARATOR.'svc-credit-race-';
foreach (['paused', 'release'] as $key) {
    if ($input[$key] !== null && (! is_string($input[$key]) || ! str_starts_with($input[$key], $barrierPrefix))) {
        exit(2);
    }
}
config(['database.connections.credit_race' => $input['connection'], 'database.default' => 'credit_race']);
DB::purge('credit_race');

$pause = static function () use ($input): void {
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

$workspace = Workspace::query()->whereKey((int) $input['workspace'])->firstOrFail();
$lifecycle = app(InvoiceLifecycleService::class);

// The funding side: a refund shrinks the settled money the pool is built from.
if ($input['mode'] === 'refund') {
    $payment = ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->whereKey((int) $input['payment'])->firstOrFail();
    $refunded = $lifecycle->setRefundedAmount($payment, (int) $input['refund'], $workspace);
    echo json_encode(['outcome' => 'success', 'refunded' => (int) $refunded->refunded_amount], JSON_THROW_ON_ERROR)."\n";
    exit(0);
}

$invoice = ClientInvoice::query()->where('workspace_id', $workspace->id)->whereKey((int) $input['invoice'])->firstOrFail();

// Hold issue() just after its ordinary (non-locking) read of the workspace:
// under REPEATABLE READ that read is what fixes the transaction's snapshot.
if ($input['mode'] === 'after-workspace-read') {
    $armed = true;
    DB::listen(static function (QueryExecuted $query) use (&$armed, $pause): void {
        if (! $armed
            || DB::transactionLevel() < 1
            || ! str_starts_with(strtolower(ltrim($query->sql)), 'select')
            || ! str_contains($query->sql, 'from `workspaces`')
            || str_contains(strtolower($query->sql), 'for update')) {
            return;
        }
        $armed = false;
        $pause();
    });
}

try {
    $issue = static fn (): ClientInvoice => $lifecycle->issue($invoice, $workspace);
    $issued = match ($input['mode']) {
        'plain', 'after-workspace-read' => $issue(),
        // A caller whose own transaction has already read something: the
        // snapshot predates issue() entirely.
        'outer-snapshot' => DB::transaction(static function () use ($issue, $pause, $workspace): ClientInvoice {
            DB::table('client_invoices')->where('workspace_id', $workspace->id)->count();
            $pause();

            return $issue();
        }),
        default => throw new LogicException('Unknown synthetic mode.'),
    };
    echo json_encode([
        'outcome' => 'success',
        'status' => $issued->status,
        'total' => (int) $issued->total_amount,
    ], JSON_THROW_ON_ERROR)."\n";
} catch (DomainException $exception) {
    echo json_encode(['outcome' => 'refused', 'class' => $exception::class, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    echo json_encode(['outcome' => 'unexpected', 'class' => $exception::class, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR)."\n";
    exit(1);
}
