<?php

use App\Models\ClientAgreement;
use App\Models\ClientBillingSchedule;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Services\Billing\BillingScheduleService;
use App\Services\Billing\ClientInvoicingService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
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
$barrierPrefix = sys_get_temp_dir().DIRECTORY_SEPARATOR.'svc-cadence-race-';
foreach (['ready', 'start', 'creating', 'create'] as $key) {
    if (! is_string($input[$key]) || ! str_starts_with($input[$key], $barrierPrefix)) {
        exit(2);
    }
}
config(['database.connections.cadence_race' => $input['connection'], 'database.default' => 'cadence_race']);
DB::purge('cadence_race');

$await = static function (string $path): void {
    $deadline = microtime(true) + 20;
    while (! is_file($path) && microtime(true) < $deadline) {
        usleep(10_000);
    }
    if (! is_file($path)) {
        exit(3);
    }
};

// Past each generator's guard and before its insert: the point where two
// transactions that do not exclude each other have both decided to bill.
ClientInvoice::creating(static function () use ($input, $await): void {
    if (! touch($input['creating'])) {
        exit(3);
    }
    $await($input['create']);
});

$company = ClientCompany::query()->where('workspace_id', (int) $input['workspace'])->whereKey((int) $input['company'])->firstOrFail();
$agreement = ClientAgreement::query()->where('workspace_id', $company->workspace_id)->whereKey((int) $input['agreement'])->firstOrFail();

if (! touch($input['ready'])) {
    exit(3);
}
$await($input['start']);

try {
    match ($input['operation']) {
        'schedule' => app(BillingScheduleService::class)->generateDue(
            ClientBillingSchedule::query()->where('workspace_id', $company->workspace_id)->whereKey((int) $input['schedule'])->firstOrFail(),
            CarbonImmutable::parse('2026-08-15'),
        ),
        'agreement' => app(ClientInvoicingService::class)->generateInvoice(
            $company,
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-31'),
            $agreement,
        ),
        default => throw new LogicException('Unknown synthetic operation.'),
    };
    echo json_encode(['outcome' => 'success'], JSON_THROW_ON_ERROR)."\n";
} catch (DomainException|RuntimeException $exception) {
    echo json_encode(['outcome' => 'refused', 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    echo json_encode(['outcome' => 'unexpected', 'class' => $exception::class, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR)."\n";
    exit(1);
}
