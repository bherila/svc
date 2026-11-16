<?php

use App\Models\ClientAgreement;
use App\Services\Engagement\AgreementWorkflow;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

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
$barrierPrefix = sys_get_temp_dir().DIRECTORY_SEPARATOR.'svc-activation-race-';
foreach (['paused', 'release'] as $key) {
    if ($input[$key] !== null && (! is_string($input[$key]) || ! str_starts_with($input[$key], $barrierPrefix))) {
        exit(2);
    }
}
config(['database.connections.activation_race' => $input['connection'], 'database.default' => 'activation_race']);
DB::purge('activation_race');

$await = static function (string $path): void {
    $deadline = microtime(true) + 20;
    while (! is_file($path) && microtime(true) < $deadline) {
        usleep(10_000);
    }
    if (! is_file($path)) {
        exit(3);
    }
};

// Held just after the package-sign-up check, the first ordinary read a draft's
// activation makes: from here on its snapshot is fixed.
if ($input['paused'] !== null && ($input['outer'] ?? null) === null) {
    $held = false;
    Event::listen(static function (QueryExecuted $query) use ($input, $await, &$held): void {
        if (! $held && str_contains($query->sql, 'service_package_enrollments')) {
            $held = true;
            if (! touch($input['paused'])) {
                exit(3);
            }
            $await($input['release']);
        }
    });
}

$agreement = ClientAgreement::query()->where('workspace_id', (int) $input['workspace'])->whereKey((int) $input['agreement'])->firstOrFail();

try {
    if (($input['outer'] ?? null) !== null) {
        // As the agent API's executor does: an outer transaction whose
        // ordinary read fixes the snapshot before activation takes any lock.
        DB::transaction(static function () use ($input, $await): void {
            $agreement = ClientAgreement::query()->where('workspace_id', (int) $input['workspace'])->whereKey((int) $input['agreement'])->firstOrFail();
            if (! touch($input['paused'])) {
                exit(3);
            }
            $await($input['release']);
            if ($input['outer'] === 'spend') {
                // As a sign-up invoice spending credit does before activation
                // (OverpaymentCreditService::recordPoolChange): the company row
                // written, so this transaction now reads its current version.
                DB::table('client_companies')->where('workspace_id', $agreement->workspace_id)->where('id', $agreement->client_company_id)
                    ->update(['credit_revision' => DB::raw('credit_revision + 1')]);
            }
            app(AgreementWorkflow::class)->activate($agreement);
        });
    } else {
        app(AgreementWorkflow::class)->activate($agreement);
    }
    echo json_encode(['outcome' => 'activated'], JSON_THROW_ON_ERROR)."\n";
} catch (DomainException|RuntimeException $exception) {
    echo json_encode(['outcome' => 'refused', 'class' => $exception::class, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    echo json_encode(['outcome' => 'unexpected', 'class' => $exception::class, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR)."\n";
    exit(1);
}
