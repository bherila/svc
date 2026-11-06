<?php

namespace App\Services\Payments;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePaymentConnection;
use App\Support\Payments\ConnectionOrigin;
use App\Support\Payments\PaymentProcessor;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;

/**
 * Enter, replace, reuse and remove a workspace's payment connections.
 *
 * Only an owner or admin of the workspace may do any of it. A key is checked
 * with a read before it is stored, the workspace's own webhook endpoint is
 * created at the processor, and the old endpoint of a replaced or removed key
 * is deleted only after the database change has committed.
 */
final class PaymentConnectionService
{
    public function __construct(
        private readonly WorkspacePaymentConnections $connections,
        private readonly StripeKeyAdapter $stripe,
        private readonly SquareTokenAdapter $square,
    ) {}

    public function adapter(PaymentProcessor $processor): PaymentConnectionAdapter
    {
        return match ($processor) {
            PaymentProcessor::Stripe => $this->stripe,
            PaymentProcessor::Square => $this->square,
        };
    }

    /** The URL the processor delivers this connection's events to. */
    public function webhookUrl(PaymentProcessor $processor, string $token): string
    {
        return rtrim((string) config('app.url'), '/').'/api/webhooks/'.$processor->value.'/'.$token;
    }

    /**
     * Store a workspace's key, replacing any earlier connection to the processor.
     *
     * @throws AuthorizationException
     * @throws PaymentCredentialsRejected
     */
    public function save(Workspace $workspace, PaymentProcessor $processor, #[SensitiveParameter] PaymentCredentials $credentials, User $actor): WorkspacePaymentConnection
    {
        $this->assertManages($actor, $workspace);
        $adapter = $this->adapter($processor);

        // Re-validated on every save, never trusted from an earlier one.
        $account = $adapter->verify($credentials);
        $token = Str::random(40);
        $registered = $adapter->registerWebhook($credentials, $this->webhookUrl($processor, $token));
        $webhookSecret = $registered?->secret() ?? $credentials->webhookSecret();
        if ($webhookSecret === null || trim($webhookSecret) === '') {
            throw new PaymentCredentialsRejected('Enter the signing key for the webhook you registered at the URL shown.');
        }

        try {
            return DB::transaction(function () use ($workspace, $processor, $credentials, $actor, $adapter, $account, $token, $registered, $webhookSecret): WorkspacePaymentConnection {
                $retired = $this->retiredWebhook($workspace, $processor);
                $connection = $this->connections->connect($workspace, $processor, ConnectionOrigin::Entered, $actor, [
                    'external_account_id' => $account->externalAccountId,
                    'display_name' => $account->displayName,
                    'environment' => $account->environment,
                    'secret' => $credentials->secret(),
                    'webhook_secret' => $webhookSecret,
                    'webhook_endpoint_id' => $registered?->endpointId,
                    'location_id' => $account->locationId,
                    'endpoint_token' => $token,
                ]);
                // The old endpoint is deleted only once this change is final.
                if ($retired !== null) {
                    DB::afterCommit(fn () => $adapter->removeWebhook($retired[0], $retired[1]));
                }

                return $connection;
            });
        } catch (Throwable $failure) {
            // Nothing was stored, so the endpoint just created points nowhere.
            if ($registered !== null) {
                $adapter->removeWebhook($credentials, $registered->endpointId);
            }

            throw $failure;
        }
    }

    /**
     * Use the account another workspace of the same owner is connected to.
     *
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function reuse(Workspace $workspace, PaymentProcessor $processor, string $sourcePublicId, User $actor): WorkspacePaymentConnection
    {
        $this->assertManages($actor, $workspace);
        $source = $this->connections->reusableFor($workspace, $processor, $actor)
            ->first(fn (WorkspacePaymentConnection $row): bool => $row->public_id === $sourcePublicId);
        if ($source === null) {
            throw new DomainException('That connection cannot be reused here.');
        }

        return $this->connections->reuse($workspace, $source, $actor);
    }

    /**
     * Remove the workspace's connection: charges stop at once, and the webhook
     * endpoint svc created is deleted after the change commits.
     *
     * @throws AuthorizationException
     */
    public function revoke(Workspace $workspace, PaymentProcessor $processor, User $actor): bool
    {
        $this->assertManages($actor, $workspace);
        $adapter = $this->adapter($processor);

        return DB::transaction(function () use ($workspace, $processor, $actor, $adapter): bool {
            $row = $this->connections->activeRow($workspace, $processor);
            if ($row === null) {
                return false;
            }
            $retired = $this->retiredWebhook($workspace, $processor);
            $revoked = $this->connections->revoke($row, $actor);
            if ($revoked && $retired !== null) {
                DB::afterCommit(fn () => $adapter->removeWebhook($retired[0], $retired[1]));
            }

            return $revoked;
        });
    }

    /**
     * The key and endpoint id of the workspace's own current endpoint, read
     * before the row is replaced or revoked; null when svc made none.
     *
     * @return array{0: PaymentCredentials, 1: string}|null
     */
    private function retiredWebhook(Workspace $workspace, PaymentProcessor $processor): ?array
    {
        $row = $this->connections->activeRow($workspace, $processor);
        if ($row === null || $row->isReused() || $row->webhook_endpoint_id === null || $row->secret() === null) {
            return null;
        }

        return [new PaymentCredentials($row->secret(), environment: $row->environment), $row->webhook_endpoint_id];
    }

    /** @throws AuthorizationException */
    private function assertManages(User $actor, Workspace $workspace): void
    {
        if (! $this->connections->manages($actor, $workspace)) {
            throw new AuthorizationException('Only a workspace owner or admin manages payment connections.');
        }
    }
}
