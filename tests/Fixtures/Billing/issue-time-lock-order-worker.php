<?php

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Approval\ClientApprovals;
use App\Services\Billing\InvoiceFromTimeService;
use App\Services\Billing\InvoiceLifecycleService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/*
 * One side of IssueTimeLockOrderTest, in a process of its own so it has
 * its own MariaDB session. Synthetic probe databases only.
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
$barrierPrefix = sys_get_temp_dir().DIRECTORY_SEPARATOR.'svc-issue-time-lock-';
foreach (['paused', 'release'] as $key) {
    if ($input[$key] !== null && (! is_string($input[$key]) || ! str_starts_with($input[$key], $barrierPrefix))) {
        exit(2);
    }
}
config(['database.connections.issue_time_lock' => $input['connection'], 'database.default' => 'issue_time_lock']);
DB::purge('issue_time_lock');

$workspace = Workspace::query()->whereKey((int) $input['workspace'])->firstOrFail();

try {
    if ($input['mode'] === 'approve') {
        $company = ClientCompany::query()->where('workspace_id', $workspace->id)->whereKey((int) $input['company'])->firstOrFail();
        $contact = User::query()->whereKey((int) $input['contact'])->firstOrFail();
        $decided = app(ClientApprovals::class)->approve($company, $contact, null, $input['time']);
        echo json_encode(['outcome' => 'success', 'time' => $decided['time']], JSON_THROW_ON_ERROR)."\n";
        exit(0);
    }

    // Held just after one locking read of time, named by `pause_on`:
    // - basis: issue()'s first phase - the agreement's disputed and settled
    //   history its held-work basis reads - before its own linked time;
    // - selection: an ad-hoc rebuild's lock of its selected time, before it
    //   deletes and recreates its lines;
    // - invoice: a hand edit's lock of its draft, before it replaces its lines.
    $armed = $input['pause_on'] !== null;
    DB::listen(static function (QueryExecuted $query) use (&$armed, $input): void {
        $sql = strtolower($query->sql);
        $matches = match ($input['pause_on']) {
            'basis' => str_starts_with($sql, 'select `client_time_entries`.`id`, `client_time_entries`.`client_disputed_at`, `client_time_entries`.`client_dispute_settlement`, `client_time_entries`.`deleted_at`'),
            'selection' => str_contains($sql, 'from `client_time_entries` where `workspace_id` = ? and `id` in'),
            'invoice' => str_contains($sql, 'from `client_invoices`'),
            default => false,
        };
        if (! $armed || DB::transactionLevel() < 1 || ! $matches || ! str_contains($sql, 'for update')) {
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

    $invoice = ClientInvoice::query()->where('workspace_id', $workspace->id)->whereKey((int) $input['invoice'])->firstOrFail();
    $result = match ($input['mode']) {
        'regenerate' => app(InvoiceFromTimeService::class)->regenerateDraftSelection($invoice, $workspace, (int) $input['entry'], dropHeld: true),
        'edit' => app(InvoiceLifecycleService::class)->updateDraft($invoice, $workspace, [], [
            ['description' => 'Synthetic adjustment', 'type' => 'adjustment', 'quantity' => '1', 'unit_amount' => 100],
        ]),
        default => app(InvoiceLifecycleService::class)->issue($invoice, $workspace),
    };
    echo json_encode(['outcome' => 'success', 'status' => $result->status, 'paused' => $input['pause_on'] !== null && ! $armed], JSON_THROW_ON_ERROR)."\n";
} catch (HttpExceptionInterface|DomainException $exception) {
    echo json_encode(['outcome' => 'refused', 'class' => $exception::class, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    echo json_encode(['outcome' => 'unexpected', 'class' => $exception::class, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR)."\n";
}
