<?php

use App\Models\User;
use App\Models\Workspace;
use App\Queries\Expenses\WorkspaceExpenseSchedules;
use App\Support\Expenses\NewExpense;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (! $app->environment('testing') || ! str_starts_with($input['connection']['database'], 'svc_probe_')) {
    exit(2);
}
config(['database.default' => 'recurrence_probe', 'database.connections.recurrence_probe' => $input['connection']]);
$workspace = Workspace::query()->findOrFail($input['workspace']);
$user = User::query()->findOrFail($input['user']);
$connectionId = DB::connection()->getPdo()->query('select connection_id()')->fetchColumn();
echo "connection:{$connectionId}\n";
DB::connection()->beforeExecuting(function (string $sql): void {
    if (str_contains($sql, 'client_expense_schedules') && str_contains(strtolower($sql), 'for update')) {
        echo "attempting-lock\n";
        flush();
    }
});
DB::listen(function ($query) use ($input): void {
    if ($input['hold'] && str_contains($query->sql, 'client_expense_schedules') && str_contains(strtolower($query->sql), 'for update')) {
        echo "locked\n";
        flush();
        if (trim(fgets(STDIN)) !== 'release') {
            throw new RuntimeException('Synthetic recurrence probe not released.');
        }
    }
});
try {
    $repository = new WorkspaceExpenseSchedules($workspace);
    if ($input['operation'] === 'pause') {
        $repository->update($input['schedule'], null, new NewExpense(CarbonImmutable::parse('2026-01-01'), 1200, 'USD', 'Synthetic recurrence race'), false);
    } else {
        $repository->generate($input['schedule'], $user);
    }
    echo "completed\n";
} catch (Throwable $exception) {
    echo $exception::class."\n";
    exit(1);
}
