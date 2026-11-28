<?php

use App\Models\User;
use App\Models\Workspace;
use App\Services\Payments\PaymentConnectionService;
use App\Support\Payments\PaymentProcessor;
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
foreach (['started', 'ready', 'release'] as $key) {
    if (! is_string($input[$key]) || ! str_starts_with($input[$key], sys_get_temp_dir().'/svc-payment-reuse-')) {
        exit(2);
    }
}
config(['database.connections.payment_reuse_worker' => $input['connection'], 'database.default' => 'payment_reuse_worker']);
DB::purge('payment_reuse_worker');
DB::statement('SET SESSION innodb_lock_wait_timeout = 15');
$workspace = Workspace::query()->findOrFail($input['target']);
$actor = User::query()->findOrFail($input['actor']);
$session = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
file_put_contents($input['started'], json_encode(['connection_id' => $session], JSON_THROW_ON_ERROR));
$observed = false;
DB::listen(function (QueryExecuted $query) use ($input, $session, &$observed): void {
    $sql = strtolower($query->sql);
    if ($observed || ! str_contains($sql, 'workspace_payment_connections') || ! str_contains($sql, 'for update')) {
        return;
    }
    $observed = true;
    if (! file_put_contents($input['ready'], json_encode(['connection_id' => $session, 'sql' => $sql], JSON_THROW_ON_ERROR))) {
        throw new RuntimeException('Could not publish the payment reuse lock barrier.');
    }
    if ($input['pause']) {
        $deadline = microtime(true) + 30;
        while (! is_file($input['release']) && microtime(true) < $deadline) {
            usleep(10_000);
        }
        if (! is_file($input['release'])) {
            throw new RuntimeException('The payment reuse lock barrier was not released.');
        }
    }
});

try {
    $created = app(PaymentConnectionService::class)->reuse($workspace, PaymentProcessor::Stripe, $input['source'], $actor);
    echo json_encode(['outcome' => 'success', 'id' => $created->id, 'source_id' => $created->reuses_connection_id], JSON_THROW_ON_ERROR)."\n";
} catch (DomainException $exception) {
    echo json_encode(['outcome' => 'refused', 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    echo json_encode(['outcome' => 'unexpected', 'class' => $exception::class, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR)."\n";
}
