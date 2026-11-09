<?php

namespace App\Console\Commands\Payments;

use App\Console\Commands\MutationCommand;
use App\Models\Workspace;
use App\Services\Payments\EnvironmentMigrationInventory;
use App\Services\Payments\PaymentConnectionService;
use App\Services\Payments\PaymentCredentialsRejected;
use App\Services\Payments\WorkspacePaymentConnections;
use App\Support\Payments\ConnectionOrigin;
use App\Support\Payments\Inventory\ImpactRole;
use App\Support\Payments\Inventory\MigrationInventory;
use App\Support\Payments\Inventory\MigrationOutcome;
use App\Support\Payments\Inventory\ProcessorInventory;
use App\Support\Payments\Inventory\WorkspaceImpact;
use App\Support\Payments\PaymentProcessor;
use Illuminate\Support\Str;

/**
 * Turns today's environment credentials (STRIPE_*, SQUARE_*, SQUARE_WORKSPACE)
 * into the chosen workspace's first payment connection.
 *
 * Run once, by hand, on the deployment. A preview unless --apply is given, and
 * a preview calls nothing and writes nothing: it prints the migration impact
 * inventory instead. Running it again changes nothing. Until it has run the
 * environment path keeps serving every payment; afterwards only the connection
 * does.
 *
 * --apply refuses to guess. When several workspaces could own a connection and
 * none was named with --workspace, it stops before writing anything.
 */
final class MigrateEnvironmentConnectionsCommand extends MutationCommand
{
    protected $signature = 'svc:payments:migrate-environment {--workspace= : Public id of the workspace to receive the connections (default: SQUARE_WORKSPACE, unless several workspaces could own them)}';

    protected $description = 'Copy the environment Stripe and Square credentials into a workspace payment connection (preview with impact inventory unless --apply)';

    public function handle(WorkspacePaymentConnections $connections, PaymentConnectionService $service, EnvironmentMigrationInventory $inventories): int
    {
        $named = null;
        if ($this->option('workspace') !== null) {
            $given = $this->option('workspace');
            $named = Str::isUuid($given) ? $this->workspaceByPublicId($given) : null;
            if (! $named instanceof Workspace) {
                $this->error('Name the workspace with --workspace=<public id>; that is not a workspace.');

                return self::INVALID;
            }
        }
        $setting = config('services.square.workspace');
        $configured = is_string($setting) && Str::isUuid($setting) ? $this->workspaceByPublicId($setting) : null;

        $inventory = $inventories->build($named, $configured);
        if ($inventory->needsExplicitWorkspace()) {
            $message = ($this->applying() ? 'Nothing was written. ' : '').'Name the workspace with --workspace=<public id>: the inventory shows who could own the connection.';
            if ($this->option('format') === 'json') {
                $this->json(['dry_run' => ! $this->applying(), 'workspace' => null, 'processors' => [], 'error' => $message, 'inventory' => $inventory->toArray()]);
            } else {
                $this->renderInventory($inventory);
                $this->error($message);
            }

            return self::INVALID;
        }

        $report = [];
        $failed = false;
        foreach (PaymentProcessor::cases() as $processor) {
            $impact = $inventory->for($processor);
            $workspace = $impact->owner === null ? null : ($named ?? $configured);
            if ($impact->outcome !== MigrationOutcome::WouldMigrate || ! $workspace instanceof Workspace) {
                $report[$processor->value] = ['status' => $impact->outcome->value];
                $failed = $failed || $impact->outcome->failsRun();

                continue;
            }
            if (! $this->applying()) {
                $report[$processor->value] = ['status' => 'would_migrate'];

                continue;
            }
            $credentials = $inventories->environmentCredentials($processor);
            if ($credentials === null) {
                $report[$processor->value] = ['status' => MigrationOutcome::NoEnvironmentCredentials->value];

                continue;
            }
            try {
                $account = $service->adapter($processor)->verify($credentials);
                // Never replaces: a connection entered since the inventory was read
                // (or since the credentials were verified) is left exactly as it is.
                $connection = $connections->connectIfNone($workspace, $processor, ConnectionOrigin::Environment, null, [
                    'external_account_id' => $account->externalAccountId,
                    'display_name' => $account->displayName,
                    'environment' => $account->environment,
                    'secret' => $credentials->secret(),
                    'webhook_secret' => $credentials->webhookSecret(),
                    'location_id' => $account->locationId,
                ]);
                if ($connection === null) {
                    $report[$processor->value] = ['status' => MigrationOutcome::WorkspaceAlreadyConnected->value, 'reason' => 'a connection was entered meanwhile and was left untouched'];
                    $failed = true;

                    continue;
                }
                $report[$processor->value] = [
                    'status' => 'migrated',
                    'webhook_url' => $service->webhookUrl($processor, $connection->endpoint_token),
                ];
            } catch (PaymentCredentialsRejected $rejected) {
                $report[$processor->value] = ['status' => 'rejected', 'reason' => $rejected->getMessage()];
                $failed = true;
            }
        }

        $owner = $named ?? $configured;
        if ($this->option('format') === 'json') {
            $payload = ['dry_run' => ! $this->applying(), 'workspace' => $owner?->public_id, 'processors' => $report];
            if (! $this->applying()) {
                $payload['inventory'] = $inventory->toArray();
            }
            $this->json($payload);
        } else {
            if (! $this->applying()) {
                $this->renderInventory($inventory);
            }
            $this->components->info(($this->applying() ? 'Applied' : 'Preview (nothing written; re-run with --apply)').' for workspace '.($owner === null ? '(none named)' : $owner->public_id).'.');
            foreach ($report as $name => $line) {
                $this->components->twoColumnDetail($name, $line['status'].(isset($line['webhook_url']) ? ' - webhook URL '.$line['webhook_url'] : '').(isset($line['reason']) ? ' - '.$line['reason'] : ''));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function renderInventory(MigrationInventory $inventory): void
    {
        $this->newLine();
        $this->line('<options=bold>Migration impact inventory</> - read only: nothing is written and neither Stripe nor Square is contacted.');
        foreach (PaymentProcessor::cases() as $processor) {
            $this->renderProcessor($inventory->for($processor));
        }
        $this->newLine();
    }

    private function renderProcessor(ProcessorInventory $inventory): void
    {
        $label = $inventory->processor->label();
        $this->newLine();
        $this->line("<options=bold>{$label}</>");
        if (! $inventory->configured) {
            $this->line('  The environment has no '.$label.' credentials, so there is nothing to move.');

            return;
        }
        $this->line('  Environment credential: '.$inventory->keyHint.' ('.$inventory->mode.' mode).');
        $this->line('  Account: not checked. A preview never calls '.$label.'; --apply reads the '.($inventory->processor === PaymentProcessor::Stripe ? 'Stripe account id' : 'Square merchant id').' from '.$label.' and stores it.');
        $this->line('  Owner: '.($inventory->owner?->label() ?? 'not decided').'. '.$inventory->ownershipRule);
        $this->line('  Result: '.$inventory->outcome->sentence());

        if ($inventory->square !== null) {
            $square = $inventory->square;
            $this->line('  Location: not checked. Rule: '.$square::RULE);
            $others = $square->otherLocationsExist();
            $this->line('  Other locations: '.match ($others) {
                true => 'yes. This workspace\'s local Square mirror lists '.$square->mirroredCount.' ('.implode(', ', $square->mirroredNames).'). If payments use any but the first one Square returns, enter the connection by hand instead.',
                false => 'none known. The local mirror lists one location ('.implode(', ', $square->mirroredNames).').',
                null => 'unknown. The local mirror has no locations (it has not synced), and a preview cannot ask Square.',
            });
        }

        $this->newLine();
        $this->line('  Workspaces that depend on the environment '.$label.' credential today:');
        if ($inventory->impacts === []) {
            $this->line('  None. No workspace has '.$label.' activity that rides on the environment credential.');
        } else {
            $this->table($this->headers($inventory->processor), array_map(fn (WorkspaceImpact $impact): array => $this->row($inventory->processor, $impact), $inventory->impacts));
            foreach ($inventory->impacts as $impact) {
                if ($impact->role !== ImpactRole::WillOwn) {
                    $this->line('  - '.$impact->workspace->name.' would stop being served by the environment '.$label.' credential'.($impact->role === ImpactRole::Candidate ? ' unless it is chosen as the owner' : '').'; its owner would need to connect '.$label.' on the Payments page.');
                }
            }
        }

        $this->newLine();
        $this->line('  Existing '.$label.' connections:');
        if ($inventory->connections === []) {
            $this->line('  None.');
        }
        foreach ($inventory->connections as $connection) {
            $this->line('  - '.$connection->sentence());
        }
    }

    /** @return list<string> */
    private function headers(PaymentProcessor $processor): array
    {
        return match ($processor) {
            PaymentProcessor::Stripe => ['Workspace', 'Role', 'Open payable invoices', 'Autopay companies', 'Saved cards', 'Pending payments', 'Held autopay attempts', 'Webhook events (90 days)'],
            PaymentProcessor::Square => ['Workspace', 'Role', 'Open invoices linked to Square', 'Linked customers', 'Pending payments', 'Webhook events (90 days)'],
        };
    }

    /** @return list<string|int> */
    private function row(PaymentProcessor $processor, WorkspaceImpact $impact): array
    {
        $counts = $impact->counts;
        $head = [$impact->workspace->name, $impact->role->label()];

        return match ($processor) {
            PaymentProcessor::Stripe => [...$head, $counts->payableInvoices, $counts->autopayCompanies, $counts->savedPaymentMethods, $counts->pendingPayments, $counts->heldAutopayAttempts, $counts->recentWebhookEvents],
            PaymentProcessor::Square => [...$head, $counts->payableInvoices, $counts->linkedCustomers, $counts->pendingPayments, $counts->recentWebhookEvents],
        };
    }
}
