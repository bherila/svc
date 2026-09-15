<?php

use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\Workspace;
use App\Services\Billing\InvoiceCorrectionService;
use App\Services\Billing\InvoiceEmailService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\Billing\InvoiceEmailDraft;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$encoded = (string) ($argv[1] ?? '');
$decoded = base64_decode($encoded, true);
if ($decoded === false) {
    exit(2);
}
$input = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
if (! $app->environment('testing') || ! str_starts_with($input['connection']['database'], 'svc_probe_')) {
    exit(2);
}
$barrierPrefix = sys_get_temp_dir().DIRECTORY_SEPARATOR.'svc-delivery-race-';
if (! str_starts_with($input['ready'], $barrierPrefix) || ! str_starts_with($input['release'], $barrierPrefix)) {
    exit(2);
}
config(['database.connections.delivery_race' => $input['connection'], 'database.default' => 'delivery_race']);
DB::purge('delivery_race');
$workspace = Workspace::query()->whereKey((int) $input['workspace'])->firstOrFail();
$invoice = ClientInvoice::query()->where('workspace_id', $workspace->id)->whereKey((int) $input['invoice'])->firstOrFail();
if (! touch($input['ready'])) {
    exit(3);
}
$deadline = microtime(true) + 15;
while (! is_file($input['release']) && microtime(true) < $deadline) {
    usleep(10_000);
}
if (! is_file($input['release'])) {
    exit(3);
}

try {
    $result = match ($input['operation']) {
        'automatic' => app(InvoiceEmailService::class)->sendAutomatic($invoice),
        'manual' => app(InvoiceEmailService::class)->send(
            $invoice,
            InvoiceEmailDraft::of(['billing@synthetic.test'], [], 'Synthetic invoice', null),
            $workspace,
        ),
        'hold' => app(InvoiceCorrectionService::class)->hold($invoice, $workspace),
        'void' => app(InvoiceLifecycleService::class)->void($invoice, $workspace),
        'pay' => app(InvoiceLifecycleService::class)->applyPayment($invoice, [
            'amount' => 100,
            'currency' => 'USD',
            'method' => 'synthetic race payment',
            'idempotency_key' => 'synthetic-race-payment',
        ], $workspace),
        'correct' => app(InvoiceCorrectionService::class)->correct(
            $invoice,
            $workspace,
            1,
            $invoice->due_date?->toDateString(),
            'Synthetic concurrent correction.',
            array_values($invoice->lines()->where('workspace_id', $workspace->id)->get()->map(fn (ClientInvoiceLine $line): array => [
                'id' => $line->public_id,
                'description' => $line->description.' corrected',
                'quantity' => (string) $line->quantity,
                'unit_amount' => (int) $line->unit_amount,
                'tax_amount' => (int) $line->tax_amount,
            ])->all()),
        ),
        default => throw new RuntimeException('Unknown synthetic operation.'),
    };
    echo json_encode(['outcome' => $result === null ? 'no-op' : 'success'], JSON_THROW_ON_ERROR)."\n";
} catch (DomainException $exception) {
    echo json_encode(['outcome' => 'refused'], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    echo json_encode(['outcome' => 'unexpected', 'class' => $exception::class], JSON_THROW_ON_ERROR)."\n";
    exit(1);
}
