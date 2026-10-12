<?php

use App\Exceptions\BillingMaintenanceBusy;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Services\Billing\ClientInvoicingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode((string) fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (! $app->environment('testing') || ! str_starts_with($input['connection']['database'], 'svc_probe_')) {
    exit(2);
}
foreach (['ready', 'acquired', 'release'] as $key) {
    if (! is_string($input[$key]) || ! str_starts_with($input[$key], sys_get_temp_dir().'/svc-maintenance-race-')) {
        exit(2);
    }
}
config(['database.connections.maintenance_race' => $input['connection'], 'database.default' => 'maintenance_race']);
DB::purge('maintenance_race');
Carbon::setTestNow('2026-09-01');
$paused = false;
DB::listen(static function (QueryExecuted $query) use ($input, &$paused): void {
    if ($paused || ! str_contains($query->sql, 'billing_maintenance_locks')
        || (! str_contains(strtolower($query->sql), 'for update') && ! str_contains(strtolower($query->sql), 'lock in share mode'))) {
        return;
    }
    $paused = true;
    if (! touch($input['acquired'])) {
        throw new RuntimeException('Could not publish the synthetic lock checkpoint.');
    }
    $deadline = microtime(true) + 15;
    while (! is_file($input['release']) && microtime(true) < $deadline) {
        usleep(10_000);
    }
    if (! is_file($input['release'])) {
        throw new RuntimeException('The synthetic lock checkpoint was not released.');
    }
});
$connectionId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
file_put_contents($input['ready'], json_encode(['connection_id' => $connectionId], JSON_THROW_ON_ERROR), LOCK_EX);
$started = microtime(true);
try {
    if ($input['operation'] === 'generate') {
        $company = ClientCompany::query()->where('workspace_id', $input['workspace'])->findOrFail($input['company']);
        $agreement = ClientAgreement::query()->where('workspace_id', $input['workspace'])->findOrFail($input['agreement']);
        app(ClientInvoicingService::class)->generateInvoice($company, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), $agreement);
        $exit = 0;
        $output = '';
    } else {
        $command = match ($input['operation']) {
            'replay' => 'svc:billing:replay',
            'rehearse' => 'svc:billing:rehearse-generation',
            default => throw new LogicException('Unknown synthetic maintenance operation.'),
        };
        $exit = Artisan::call($command, ['--workspace' => $input['workspace_public_id'], '--company' => $input['company_public_id']]);
        $output = Artisan::output();
    }
    echo json_encode(['exit' => $exit, 'output' => $output, 'seconds' => microtime(true) - $started, 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR)."\n";
} catch (BillingMaintenanceBusy $exception) {
    echo json_encode(['exit' => 1, 'status' => $exception->getStatusCode(), 'output' => $exception->getMessage(), 'seconds' => microtime(true) - $started, 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    echo json_encode(['unexpected' => $exception::class, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR)."\n";
    exit(1);
}
