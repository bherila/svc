<?php

use App\Models\AgentMutationReceipt;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentMutationExecutor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Synthetic two-process probe only. Connection settings arrive over stdin and
// are never logged or persisted. The schema must have the probe prefix.
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (! $app->environment('testing') || ! str_starts_with($input['connection']['database'], 'svc_probe_')) {
    exit(2);
}
config(['database.default' => 'receipt_probe', 'database.connections.receipt_probe' => $input['connection']]);
$user = User::query()->findOrFail($input['user']);
$workspace = Workspace::query()->findOrFail($input['workspace']);
$called = false;
$callback = function () use (&$called, $input): array {
    $called = true;
    echo "reserved\n";
    flush();
    if ($input['hold']) {
        if (trim(fgets(STDIN)) !== 'release') {
            throw new RuntimeException('The probe was not released.');
        }
    }

    return ['synthetic-'.$input['client']];
};
DB::connection()->beforeStartingTransaction(function (Connection $connection): void {
    $connectionId = $connection->getPdo()->query('select connection_id()')->fetchColumn();
    echo "connection:{$connectionId}\nstarted\n";
    flush();
});
DB::connection()->beforeExecuting(function (string $query): void {
    if (str_contains($query, 'agent_mutation_receipts')) {
        echo "attempting-receipt\n";
        flush();
    }
});
try {
    if ($input['writer'] === 'new') {
        $ids = app(AgentMutationExecutor::class)->run($user, $workspace, $input['client'], 'tasks.create', 'synthetic-race-key', [], $callback);
    } else {
        // The pre-cutover algorithm: insert before the callback; on collision,
        // replay only a completed receipt with the same request digest.
        try {
            $ids = DB::transaction(function () use ($user, $workspace, $callback): array {
                $receipt = AgentMutationReceipt::query()->create(['user_id' => $user->id, 'workspace_id' => $workspace->id, 'oauth_client_id' => 'testing-client', 'operation' => 'tasks.create', 'idempotency_key' => 'synthetic-race-key', 'request_digest' => hash('sha256', '[]'), 'status' => 'pending', 'result_public_ids' => []]);
                $ids = $callback();
                $receipt->forceFill(['status' => 'completed', 'result_public_ids' => $ids, 'completed_at' => now()])->save();

                return $ids;
            });
        } catch (UniqueConstraintViolationException) {
            $receipt = AgentMutationReceipt::query()->where('workspace_id', $workspace->id)->where('user_id', $user->id)->where('oauth_client_id', 'testing-client')->where('operation', 'tasks.create')->where('idempotency_key', 'synthetic-race-key')->firstOrFail();
            abort_unless(hash_equals($receipt->request_digest, hash('sha256', '[]')) && $receipt->status === 'completed', 409);
            $ids = $receipt->result_public_ids;
        }
    }
    echo json_encode(['outcome' => 'success', 'callback' => $called, 'ids' => $ids], JSON_THROW_ON_ERROR)."\n";
} catch (HttpException $exception) {
    echo json_encode(['outcome' => $exception->getStatusCode(), 'callback' => $called], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    // Exception messages may contain connection settings; expose only the class.
    echo json_encode(['outcome' => 'unexpected', 'class' => $exception::class, 'callback' => $called], JSON_THROW_ON_ERROR)."\n";
    exit(1);
}
