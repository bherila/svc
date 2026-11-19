<?php

use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\TimeEntryMutationService;
use App\Services\Billing\BillingEligibilityOrder;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Engagement\TimeEntryWorkflow;
use App\Support\AgentApi\AgentApiVersion;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
$prefix = sys_get_temp_dir().DIRECTORY_SEPARATOR.'svc-eligibility-order-';
foreach (['started', 'ready', 'release'] as $key) {
    if (($input[$key] ?? null) !== null && (! is_string($input[$key]) || ! str_starts_with($input[$key], $prefix))) {
        exit(2);
    }
}
config(['database.connections.eligibility_order_worker' => $input['connection'], 'database.default' => 'eligibility_order_worker', 'mail.default' => 'array']);
DB::purge('eligibility_order_worker');
DB::statement('SET SESSION innodb_lock_wait_timeout = 15');
CarbonImmutable::setTestNow('2026-02-01 12:00:00');
Carbon::setTestNow('2026-02-01 12:00:00');
Mail::fake();
$workspace = Workspace::query()->whereKey((int) $input['workspace'])->firstOrFail();
$connectionId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
if (! file_put_contents($input['started'], json_encode(['connection_id' => $connectionId], JSON_THROW_ON_ERROR))) {
    exit(3);
}

try {
    $createdEntryId = null;
    $eventId = DB::transaction(function () use ($input, $workspace, $connectionId, &$createdEntryId): int {
        if ($input['mode'] === 'event') {
            $eventId = app(BillingEligibilityOrder::class)->record($workspace->id, (int) $input['company']);
        } elseif (in_array($input['mode'], ['approve', 'create'], true)) {
            if ($input['mode'] === 'create') {
                $project = ClientProject::query()->where('workspace_id', $workspace->id)->whereKey((int) $input['project'])->firstOrFail();
                $author = User::query()->whereKey((int) $input['author'])->firstOrFail();
                $entry = app(TimeEntryWorkflow::class)->create($workspace, $project, $author, [
                    'worked_on' => '2026-01-15', 'minutes' => 30, 'description' => 'Synthetic concurrent late creation',
                    'billing_rate_amount' => 10000, 'currency' => 'USD',
                ]);
                $createdEntryId = $entry->id;
            } else {
                $entry = ClientTimeEntry::query()->where('workspace_id', $workspace->id)->whereKey((int) $input['entry'])->firstOrFail();
            }
            $actor = User::query()->whereKey((int) $input['actor'])->firstOrFail();
            app(TimeEntryMutationService::class)->approve($workspace, $actor, [['id' => $entry->public_id, 'expected_version' => AgentApiVersion::for($entry)]]);
            $eventId = (int) $entry->fresh()->billing_eligibility_event_id;
        } elseif ($input['mode'] === 'issue') {
            $invoice = ClientInvoice::query()->where('workspace_id', $workspace->id)->whereKey((int) $input['invoice'])->firstOrFail();
            $issued = app(InvoiceLifecycleService::class)->issue($invoice, $workspace);
            $eventId = (int) $issued->billing_eligibility_event_id;
        } else {
            throw new LogicException('Unknown synthetic eligibility mode.');
        }
        $sequence = DB::table('client_billing_eligibility_events')->where('workspace_id', $workspace->id)->where('id', $eventId)->value('sequence');
        if ($eventId < 1 || $sequence !== null) {
            throw new LogicException('The outer transaction must still own an unsequenced event.');
        }
        if (($input['ready'] ?? null) !== null) {
            if (! file_put_contents($input['ready'], json_encode(['event_id' => $eventId, 'sequence' => $sequence, 'connection_id' => $connectionId], JSON_THROW_ON_ERROR))) {
                throw new RuntimeException('Could not publish the synthetic allocation barrier.');
            }
            $deadline = microtime(true) + 30;
            while (! is_file($input['release']) && microtime(true) < $deadline) {
                usleep(10_000);
            }
            if (! is_file($input['release'])) {
                throw new RuntimeException('The synthetic eligibility barrier was not released.');
            }
        }

        return $eventId;
    });
    $sequence = DB::table('client_billing_eligibility_events')->where('workspace_id', $workspace->id)->where('id', $eventId)->value('sequence');
    echo json_encode(['outcome' => 'success', 'event_id' => $eventId, 'sequence' => (int) $sequence, 'connection_id' => $connectionId, 'entry_id' => $createdEntryId], JSON_THROW_ON_ERROR)."\n";
} catch (HttpExceptionInterface|DomainException $exception) {
    echo json_encode(['outcome' => 'refused', 'class' => $exception::class, 'message' => $exception->getMessage(), 'connection_id' => $connectionId], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    echo json_encode(['outcome' => 'unexpected', 'class' => $exception::class, 'message' => $exception->getMessage(), 'connection_id' => $connectionId], JSON_THROW_ON_ERROR)."\n";
}
