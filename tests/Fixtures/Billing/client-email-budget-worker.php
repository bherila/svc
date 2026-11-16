<?php

use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Services\Billing\InvoiceEmailService;
use App\Support\Billing\InvoiceEmailDraft;
use App\Support\Concurrency\Locks;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/**
 * One manual send in its own MariaDB session, for
 * ManualClientEmailBudgetConcurrencyTest, shaped like the agent API's: the
 * transaction share-locks the workspace row first, as the receipt insert's
 * foreign key check does, and reads before the send, as the executor does, so
 * its snapshot predates whatever commits while it waits.
 *
 * With `hold`, it stops once its delivery is inserted, still holding the
 * budget key, until the test writes "release". With `await`, it stops after
 * taking its snapshot until the test writes "go".
 */
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode((string) fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (! $app->environment('testing') || ! str_starts_with($input['connection']['database'], 'svc_probe_')) {
    exit(2);
}
config([
    'database.default' => 'client_email_budget_probe',
    'database.connections.client_email_budget_probe' => $input['connection'],
    'mail.default' => 'array',
    'svc.client_email.daily_recipients' => $input['limit'],
]);
$workspace = Workspace::query()->findOrFail($input['workspace']);
$connectionId = DB::connection()->getPdo()->query('select connection_id()')->fetchColumn();
echo "connection:{$connectionId}\n";
echo 'isolation:'.DB::connection()->getPdo()->query('select @@session.tx_isolation')->fetchColumn()."\n";
DB::connection()->beforeExecuting(function (string $sql): void {
    if (str_contains($sql, 'client_email_budget_locks') && str_starts_with(strtolower(ltrim($sql)), 'insert')) {
        echo "attempting-lock\n";
        flush();
    }
});
DB::listen(function ($query) use ($input): void {
    if ($input['hold'] && str_contains($query->sql, 'client_invoice_email_deliveries') && str_starts_with(strtolower(ltrim($query->sql)), 'insert')) {
        echo "admitted\n";
        flush();
        if (trim((string) fgets(STDIN)) !== 'release') {
            throw new RuntimeException('Synthetic budget probe not released.');
        }
    }
});
try {
    DB::transaction(function () use ($workspace, $input): void {
        Workspace::query()->whereKey($workspace->id)->tap(Locks::forShare())->firstOrFail();
        $invoice = ClientInvoice::query()->where('workspace_id', $workspace->id)->findOrFail($input['invoice']);
        if ($input['await']) {
            echo "snapshot\n";
            flush();
            if (trim((string) fgets(STDIN)) !== 'go') {
                throw new RuntimeException('Synthetic budget probe not started.');
            }
        }
        app(InvoiceEmailService::class)->sendAfterCommit($invoice, InvoiceEmailDraft::of($input['recipients'], [], 'Synthetic budget race', null), $workspace);
    });
    echo "sent\n";
} catch (DomainException $refused) {
    echo 'refused: '.$refused->getMessage()."\n";
} catch (Throwable $exception) {
    echo $exception::class.': '.$exception->getMessage()."\n";
    exit(1);
}
