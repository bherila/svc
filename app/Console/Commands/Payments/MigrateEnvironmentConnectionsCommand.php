<?php

namespace App\Console\Commands\Payments;

use App\Console\Commands\MutationCommand;
use App\Models\Workspace;
use App\Services\Payments\PaymentConnectionService;
use App\Services\Payments\PaymentCredentials;
use App\Services\Payments\PaymentCredentialsRejected;
use App\Services\Payments\WorkspacePaymentConnections;
use App\Support\Payments\ConnectionOrigin;
use App\Support\Payments\PaymentProcessor;
use Illuminate\Support\Str;

/**
 * Turns today's environment credentials (STRIPE_*, SQUARE_*, SQUARE_WORKSPACE)
 * into the configured workspace's first payment connection.
 *
 * Run once, by hand, on the deployment. A preview unless --apply is given, and
 * a preview calls nothing. Running it again changes nothing. Until it has run
 * the environment path keeps serving every payment; afterwards only the
 * connection does.
 */
final class MigrateEnvironmentConnectionsCommand extends MutationCommand
{
    protected $signature = 'svc:payments:migrate-environment {--workspace= : Public id of the workspace to receive the connections (default: SQUARE_WORKSPACE)}';

    protected $description = 'Copy the environment Stripe and Square credentials into a workspace payment connection (preview unless --apply)';

    public function handle(WorkspacePaymentConnections $connections, PaymentConnectionService $service): int
    {
        $publicId = $this->option('workspace') ?? config('services.square.workspace');
        $workspace = is_string($publicId) && Str::isUuid($publicId) ? $this->workspaceByPublicId($publicId) : null;
        if (! $workspace instanceof Workspace) {
            $this->error('Name the workspace with --workspace=<public id>, or set SQUARE_WORKSPACE.');

            return self::INVALID;
        }

        $report = [];
        $failed = false;
        foreach (PaymentProcessor::cases() as $processor) {
            $credentials = $this->environmentCredentials($processor);
            if ($credentials === null) {
                $report[$processor->value] = ['status' => 'no_environment_credentials'];

                continue;
            }
            if ($connections->environmentMigrated($processor)) {
                $report[$processor->value] = ['status' => 'already_migrated'];

                continue;
            }
            if ($connections->activeRow($workspace, $processor) !== null) {
                // Never replace a connection someone entered on purpose.
                $report[$processor->value] = ['status' => 'workspace_already_connected'];
                $failed = true;

                continue;
            }
            if (! $this->applying()) {
                $report[$processor->value] = ['status' => 'would_migrate'];

                continue;
            }
            try {
                $account = $service->adapter($processor)->verify($credentials);
                $connection = $connections->connect($workspace, $processor, ConnectionOrigin::Environment, null, [
                    'external_account_id' => $account->externalAccountId,
                    'display_name' => $account->displayName,
                    'environment' => $account->environment,
                    'secret' => $credentials->secret(),
                    'webhook_secret' => $credentials->webhookSecret(),
                    'location_id' => $account->locationId,
                ]);
                $report[$processor->value] = [
                    'status' => 'migrated',
                    'webhook_url' => $service->webhookUrl($processor, $connection->endpoint_token),
                ];
            } catch (PaymentCredentialsRejected $rejected) {
                $report[$processor->value] = ['status' => 'rejected', 'reason' => $rejected->getMessage()];
                $failed = true;
            }
        }

        if ($this->option('format') === 'json') {
            $this->json(['dry_run' => ! $this->applying(), 'workspace' => $workspace->public_id, 'processors' => $report]);
        } else {
            $this->components->info(($this->applying() ? 'Applied' : 'Preview (nothing written; re-run with --apply)').' for workspace '.$workspace->public_id.'.');
            foreach ($report as $name => $line) {
                $this->components->twoColumnDetail($name, $line['status'].(isset($line['webhook_url']) ? ' - webhook URL '.$line['webhook_url'] : '').(isset($line['reason']) ? ' - '.$line['reason'] : ''));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function environmentCredentials(PaymentProcessor $processor): ?PaymentCredentials
    {
        $string = static fn (string $key): ?string => is_string($value = config($key)) && trim($value) !== '' ? trim($value) : null;

        return match ($processor) {
            PaymentProcessor::Stripe => ($secret = $string('services.stripe.secret_key')) === null
                ? null
                : new PaymentCredentials($secret, $string('services.stripe.webhook_secret')),
            PaymentProcessor::Square => ($token = $string('services.square.access_token')) === null
                ? null
                : new PaymentCredentials($token, $string('services.square.webhook_signature_key'), null, $string('services.square.environment') ?? 'production'),
        };
    }
}
