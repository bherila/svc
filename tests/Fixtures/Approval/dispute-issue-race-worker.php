<?php

use App\Models\ClientExpense;
use App\Models\ClientInvoice;
use App\Models\ClientTimeEntry;
use App\Models\Workspace;
use App\Services\Billing\BillingMaintenance;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\Concurrency\Locks;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
 * One side of DisputeIssueRaceTest, in a process of its own so it has its own
 * MariaDB session. Synthetic probe databases only.
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
$barrierPrefix = sys_get_temp_dir().DIRECTORY_SEPARATOR.'svc-dispute-race-';
foreach (['paused', 'release'] as $key) {
    if ($input[$key] !== null && (! is_string($input[$key]) || ! str_starts_with($input[$key], $barrierPrefix))) {
        exit(2);
    }
}
config(['database.connections.dispute_race' => $input['connection'], 'database.default' => 'dispute_race']);
DB::purge('dispute_race');

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

try {
    // The client's dispute, stamped under the row lock ClientApprovals::dispose()
    // takes - plus the maintenance share, which is compatible with the
    // issue's own and so changes no contention - with its draft rebuild left
    // out: the case the issue guard exists for is a rebuild that did not
    // happen.
    if ($input['mode'] === 'dispute') {
        DB::transaction(static function () use ($workspace, $input): void {
            BillingMaintenance::share($workspace->id);
            $model = $input['row'] === 'time' ? ClientTimeEntry::class : ClientExpense::class;
            $row = $model::query()->where('workspace_id', $workspace->id)->whereKey((int) $input['row_id'])->tap(Locks::forUpdate())->firstOrFail();
            $model::query()->where('workspace_id', $workspace->id)->whereKey($row->id)->update([
                'client_disputed_at' => now(),
                'client_dispute_note' => 'Synthetic race dispute',
                'lock_version' => DB::raw('lock_version + 1'),
            ]);
        });
        echo json_encode(['outcome' => 'success'], JSON_THROW_ON_ERROR)."\n";
        exit(0);
    }

    $invoice = ClientInvoice::query()->where('workspace_id', $workspace->id)->whereKey((int) $input['invoice'])->firstOrFail();
    $lifecycle = app(InvoiceLifecycleService::class);

    // Held inside issue(), just after the guard's last row lock - the
    // expenses - so the charged time and expenses are both locked.
    if ($input['mode'] === 'held-after-guard') {
        $armed = true;
        DB::listen(static function (QueryExecuted $query) use (&$armed, $pause): void {
            if (! $armed
                || DB::transactionLevel() < 1
                || ! str_contains($query->sql, 'from `client_expenses`')
                || ! str_contains(strtolower($query->sql), 'for update')) {
                return;
            }
            $armed = false;
            $pause();
        });
    }

    $issue = static fn (): ClientInvoice => $lifecycle->issue($invoice, $workspace);
    $issued = match ($input['mode']) {
        'held-after-guard' => $issue(),
        // A caller whose own transaction read something first: its snapshot
        // predates the dispute that commits while it is paused.
        'outer-snapshot' => DB::transaction(static function () use ($issue, $pause, $workspace): ClientInvoice {
            DB::table('client_invoices')->where('workspace_id', $workspace->id)->count();
            $pause();

            return $issue();
        }),
        default => throw new LogicException('Unknown synthetic mode.'),
    };
    echo json_encode(['outcome' => 'success', 'status' => $issued->status], JSON_THROW_ON_ERROR)."\n";
} catch (DomainException $exception) {
    echo json_encode(['outcome' => 'refused', 'class' => $exception::class, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    echo json_encode(['outcome' => 'unexpected', 'class' => $exception::class, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR)."\n";
    exit(1);
}
