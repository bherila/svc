<?php

use App\Models\ClientAgreement;
use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Services\Billing\DeferredBuyDownAction;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\Billing\DeferredBuyDownRequest;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/*
 * The caller side of BuyDownAttachSnapshotTest, in a process of its own so it
 * has its own MariaDB session. Synthetic probe databases only.
 *
 * Like the API's mutation executor, it opens a transaction and reads before
 * the billing action takes a lock - the backlog's version and the target - so
 * its REPEATABLE READ snapshot is fixed there. It then waits while the test
 * moves the state on, and attaches the buy-down (mode `attach`) or issues the
 * target (mode `issue`) from that older snapshot.
 */

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
$barrierPrefix = sys_get_temp_dir().DIRECTORY_SEPARATOR.'svc-buydown-snapshot-';
foreach (['paused', 'release'] as $key) {
    if (! is_string($input[$key]) || ! str_starts_with($input[$key], $barrierPrefix)) {
        exit(2);
    }
}
config(['database.connections.buydown_snapshot' => $input['connection'], 'database.default' => 'buydown_snapshot']);
DB::purge('buydown_snapshot');

try {
    $attached = DB::transaction(static function () use ($input): ClientInvoice {
        $workspace = Workspace::query()->whereKey((int) $input['workspace'])->firstOrFail();
        $agreement = ClientAgreement::query()->where('workspace_id', $workspace->id)->whereKey((int) $input['agreement'])->firstOrFail();
        $target = ClientInvoice::query()->where('workspace_id', $workspace->id)->whereKey((int) $input['invoice'])->firstOrFail();
        $billing = app(DeferredBuyDownAction::class);
        $request = DeferredBuyDownRequest::from(['hours' => 1]);
        $version = $billing->preview($workspace, $agreement, $request)->version;
        $target->lines()->get();

        if (! touch($input['paused'])) {
            exit(3);
        }
        $deadline = microtime(true) + 60;
        while (! is_file($input['release']) && microtime(true) < $deadline) {
            usleep(10_000);
        }
        if (! is_file($input['release'])) {
            exit(3);
        }

        return ($input['mode'] ?? 'attach') === 'issue'
            ? app(InvoiceLifecycleService::class)->issue($target, $workspace)
            : $billing->create($workspace, $agreement, $request, $version, $target);
    });
    echo json_encode(['outcome' => 'success', 'total' => (int) $attached->total_amount, 'lines' => $attached->lines->count()], JSON_THROW_ON_ERROR)."\n";
} catch (DomainException $exception) {
    echo json_encode(['outcome' => 'refused', 'class' => $exception::class, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    echo json_encode(['outcome' => 'unexpected', 'class' => $exception::class, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR)."\n";
    exit(1);
}
