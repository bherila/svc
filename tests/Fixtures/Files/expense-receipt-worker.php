<?php

use App\Models\ClientExpense;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Expenses\WorkspaceExpenses;
use App\Services\Files\AttachmentStorageService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (! $app->environment('testing') || ! str_starts_with($input['connection']['database'], 'svc_probe_')
    || ! str_starts_with(basename($input['storage']), 'svc-receipt-probe-')) {
    exit(2);
}
config(['database.default' => 'expense_receipt_probe', 'database.connections.expense_receipt_probe' => $input['connection'],
    'svc.filesystem_disk' => 'receipt_probe', 'filesystems.disks.receipt_probe' => ['driver' => 'local', 'root' => $input['storage']]]);
$workspace = Workspace::query()->findOrFail($input['workspace']);
$user = User::query()->findOrFail($input['user']);
// Resolve before contending, just as the HTTP controller does. The losing
// uploader must not trust this live but subsequently stale parent instance.
$expense = ClientExpense::query()->where('workspace_id', $workspace->id)->whereKey($input['expense'])->firstOrFail();
$connectionId = DB::connection()->getPdo()->query('select connection_id()')->fetchColumn();
echo "connection:{$connectionId}\n";
DB::connection()->beforeExecuting(function (string $sql): void {
    if (str_contains($sql, 'client_expenses') && str_contains(strtolower($sql), 'for update')) {
        echo "attempting-lock\n";
        flush();
    }
});
DB::listen(function ($event) use ($input): void {
    if ($input['hold'] && str_contains($event->sql, 'client_expenses') && str_contains(strtolower($event->sql), 'for update')) {
        echo "locked\n";
        flush();
        if (trim(fgets(STDIN)) !== 'release') {
            throw new RuntimeException('Synthetic receipt probe not released.');
        }
    }
});
try {
    if ($input['operation'] === 'discard') {
        (new WorkspaceExpenses($workspace))->discard($expense);
    } else {
        app(AttachmentStorageService::class)->store($workspace, $expense, UploadedFile::fake()->createWithContent('synthetic.txt', 'Synthetic receipt race'), $user);
    }
    echo "outcome:success\n";
} catch (ModelNotFoundException) {
    echo "outcome:404\n";
} catch (Throwable $exception) {
    echo $exception::class."\n";
    exit(1);
}
