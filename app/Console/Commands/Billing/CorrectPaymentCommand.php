<?php

namespace App\Console\Commands\Billing;

use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\AgentApi\AgentApiVersion;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The operator's door to {@see InvoiceLifecycleService::correctPayment()}.
 *
 * Only the descriptive fields can be named, because only they have options:
 * there is no `--amount` or `--status` for a caller to try. An option that is
 * left out is left alone; one given empty (`--reference=`) clears a nullable
 * field. `--dry-run` runs the whole correction - every bound, the version
 * check, the no-op rule - inside a transaction that is always rolled back, so
 * what it reports is what a real run would do rather than a separate guess.
 */
class CorrectPaymentCommand extends Command
{
    protected $signature = 'svc:billing:correct-payment
        {payment : Payment public UUID}
        {--workspace= : Workspace public UUID (required)}
        {--method= : How the money arrived (required text, at most 40 characters)}
        {--reference= : External reference; pass it empty to clear}
        {--notes= : Internal note; pass it empty to clear}
        {--received-on= : The day the money arrived, YYYY-MM-DD}
        {--reason= : Why the payment is being corrected (required)}
        {--expected-version= : Refuse unless the payment is still at this version}
        {--dry-run : Report what would change and write nothing}
        {--format=text : Output text or json}';

    protected $description = 'Correct a payment\'s method, reference, notes or received date, with a recorded reason';

    public function handle(InvoiceLifecycleService $service): int
    {
        $format = (string) $this->option('format');
        if (! in_array($format, ['text', 'json'], true)) {
            $this->error('The --format option must be text or json.');

            return self::INVALID;
        }
        $workspaceId = (string) $this->option('workspace');
        if ($workspaceId === '') {
            $this->error('The --workspace option is required.');

            return self::INVALID;
        }

        $changes = [];
        foreach (['method' => 'method', 'reference' => 'reference', 'notes' => 'notes', 'received-on' => 'received_on'] as $option => $field) {
            $value = $this->option($option);
            if ($value !== null) {
                $changes[$field] = (string) $value;
            }
        }
        if ($changes === []) {
            $this->error('Name at least one of --method, --reference, --notes or --received-on.');

            return self::INVALID;
        }

        $workspace = Workspace::query()->where('public_id', $workspaceId)->first();
        $payment = $workspace === null ? null : ClientInvoicePayment::query()
            ->where('workspace_id', $workspace->id)
            ->where('public_id', (string) $this->argument('payment'))
            ->first();
        if ($workspace === null || $payment === null) {
            $this->error('Payment not found in the requested workspace.');

            return self::FAILURE;
        }

        $expected = $this->option('expected-version');
        $dryRun = (bool) $this->option('dry-run');

        // Reported from the row the service locked, never from the lookup
        // above: another write can land between the two, and a report built
        // from the earlier read would describe a change nobody made.
        DB::beginTransaction();
        try {
            $outcome = $service->correctPaymentReporting(
                $payment,
                $changes,
                (string) $this->option('reason'),
                is_string($expected) && $expected !== '' ? $expected : null,
                $workspace,
            );
            // After a dry run the row is back to how it was locked.
            $version = $dryRun ? $outcome->lockedVersion : AgentApiVersion::for($outcome->payment);
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (DomainException $exception) {
            DB::rollBack();
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        $diff = $outcome->changes;
        $invoice = ClientInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($payment->client_invoice_id)
            ->value('public_id');
        $result = [
            'payment_public_id' => $payment->public_id,
            'invoice_public_id' => $invoice,
            'dry_run' => $dryRun,
            'changed' => $diff !== [],
            'changes' => $diff,
            'version' => $version,
        ];

        if ($format === 'json') {
            $this->line((string) json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Payment', $payment->public_id);
        if ($diff === []) {
            $this->components->info('Nothing to change: the payment already reads that way.');
        }
        foreach ($diff as $field => $change) {
            $this->components->twoColumnDetail($field, ($change['old'] ?? '(none)').' → '.($change['new'] ?? '(none)'));
        }
        if ($dryRun && $diff !== []) {
            $this->components->warn('Dry run: nothing was written.');
        }
        $this->components->twoColumnDetail('Version', $result['version']);

        return self::SUCCESS;
    }
}
