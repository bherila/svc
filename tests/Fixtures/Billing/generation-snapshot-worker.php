<?php

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientTimeEntry;
use App\Models\Workspace;
use App\Services\Billing\AllocationService;
use App\Services\Billing\BillingMaintenance;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InvoiceFromTimeService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Billing\InvoiceNumberAllocator;
use App\Support\Concurrency\Locks;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * The generator side of CadenceGenerationSnapshotTest, in a process of its own
 * so it has its own MariaDB session. Synthetic probe databases only.
 *
 * It generates a cadence period in a transaction of its own and holds at that
 * transaction's first ordinary read - where REPEATABLE READ fixes its snapshot -
 * until the test has committed a change to the time it is about to price.
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
$barrierPrefix = sys_get_temp_dir().DIRECTORY_SEPARATOR.'svc-generation-snapshot-';
foreach (['paused', 'release'] as $key) {
    if (! is_string($input[$key]) || ! str_starts_with($input[$key], $barrierPrefix)) {
        exit(2);
    }
}
if (($input['period'] ?? null) !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $input['period'])) {
    exit(2);
}
config(['database.connections.generation_snapshot' => $input['connection'], 'database.default' => 'generation_snapshot']);
DB::purge('generation_snapshot');
// Reported with the outcome, so a test that asks for an isolation level can
// see its session got it rather than the server's default.
$isolation = (string) DB::selectOne('select @@session.tx_isolation as level')->level;
if (isset($input['connection_file'])) {
    if (! is_string($input['connection_file']) || ! str_starts_with($input['connection_file'], $barrierPrefix)) {
        exit(2);
    }
    file_put_contents($input['connection_file'], (string) DB::selectOne('select connection_id() as id')->id);
}

$workspace = Workspace::query()->whereKey((int) $input['workspace'])->firstOrFail();
$company = ClientCompany::query()->where('workspace_id', $workspace->id)->whereKey((int) $input['company'])->firstOrFail();
$agreement = ClientAgreement::query()->where('workspace_id', $workspace->id)->whereKey((int) ($input['agreement'] ?? 0))->first();

// Where to hold: by default the transaction's first ordinary read; with
// `pause_on`, the first statement containing that text; with `pause_on` set to
// `never`, nowhere.
$pauseOn = $input['pause_on'] ?? null;
$armed = $pauseOn !== 'never';
DB::listen(static function (QueryExecuted $query) use (&$armed, $input, $pauseOn): void {
    $sql = strtolower($query->sql);
    if (! $armed || DB::transactionLevel() < 1) {
        return;
    }
    if (($input['pause_lock_only'] ?? false) && ! str_contains($sql, 'for update') && ! str_contains($sql, 'lock in share mode')) {
        return;
    }
    if (is_string($pauseOn) ? ! str_contains($sql, strtolower($pauseOn)) : (! str_starts_with(ltrim($sql), 'select')
        || ! str_contains($sql, ' from `') || str_contains($sql, 'for update') || str_contains($sql, 'lock in share mode'))) {
        return;
    }
    $armed = false;
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
});

try {
    if (($input['operation'] ?? 'generate') === 'numbered-time-lock') {
        DB::transaction(function () use ($workspace, $input): void {
            BillingMaintenance::share($workspace->id);
            app(InvoiceNumberAllocator::class)->lockNumbering($workspace->id);
            ClientTimeEntry::query()->where('workspace_id', $workspace->id)
                ->whereKey((int) $input['entry_id'])->tap(Locks::forUpdate())->firstOrFail();
        });
        echo json_encode(['outcome' => 'success'], JSON_THROW_ON_ERROR)."\n";
        exit(0);
    }
    if (($input['operation'] ?? 'generate') === 'issue') {
        $invoice = ClientInvoice::query()->where('workspace_id', $workspace->id)->findOrFail((int) $input['invoice']);
        app(InvoiceLifecycleService::class)->issue($invoice, $workspace);
        echo json_encode(['outcome' => 'success'], JSON_THROW_ON_ERROR)."\n";
        exit(0);
    }
    if (($input['operation'] ?? 'generate') === 'selected') {
        app(InvoiceFromTimeService::class)->create($workspace, $company, [
            'invoice_number' => 'SYN-SELECTED-1', 'currency' => 'USD',
        ], [(string) $input['entry']]);
        echo json_encode(['outcome' => 'success'], JSON_THROW_ON_ERROR)."\n";
        exit(0);
    }
    if (($input['operation'] ?? 'generate') === 'recombine') {
        $merged = app(AllocationService::class)->recombineUnlinkedFragments($workspace, $company);
        echo json_encode(['outcome' => 'success', 'merged' => $merged], JSON_THROW_ON_ERROR)."\n";
        exit(0);
    }
    $draft = app(ClientInvoicingService::class)->generateCadencePeriod($company, $agreement, Carbon::parse((string) $input['period']));
    echo json_encode(['outcome' => 'success', 'invoice' => $draft->id, 'hours' => (string) $draft->hours_billed_at_rate, 'isolation' => $isolation], JSON_THROW_ON_ERROR)."\n";
} catch (QueryException $failure) {
    echo json_encode(['outcome' => 'failed', 'class' => $failure::class, 'code' => $failure->errorInfo[1] ?? null, 'message' => $failure->getMessage()], JSON_THROW_ON_ERROR)."\n";
} catch (DomainException|RuntimeException $refusal) {
    echo json_encode(['outcome' => 'refused', 'class' => $refusal::class, 'message' => $refusal->getMessage(), 'isolation' => $isolation], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    echo json_encode(['outcome' => 'unexpected', 'class' => $exception::class, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR)."\n";
    exit(1);
}
