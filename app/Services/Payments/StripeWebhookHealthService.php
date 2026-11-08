<?php

namespace App\Services\Payments;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePaymentConnection;
use App\Services\Billing\StripeWebhookService;
use App\Support\Payments\ConnectionOrigin;
use App\Support\Payments\PaymentProcessor;
use App\Support\Payments\StripeWebhookHealthDecision;
use App\Support\Payments\WebhookHealth;
use App\Support\Payments\WebhookHealthStatus;
use App\Support\Payments\WebhookUrl;
use App\Support\WorkspaceClock;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Whether the Stripe account's webhook endpoint is subscribed to every event
 * SVC needs, and, for an owner or admin, making it so.
 *
 * A check lists the account's webhook endpoints with the connection's own
 * restricted key and compares the one at SVC's URL with
 * {@see StripeWebhookService::REQUIRED_EVENTS}; the answer is stored on the
 * connection's row (workspace-scoped like the key) for the Payments page. Every
 * Stripe call here is made outside any database transaction of this class.
 *
 * Fixing never removes an event and never touches another endpoint. Adding
 * events is offered only for the stored endpoint SVC created. When that endpoint
 * is gone, or the connection records none, SVC cannot say which endpoint the
 * stored signing secret belongs to; the repair is an explicit reconnect that
 * creates a new endpoint and stores its id and its signing secret together
 * (never one without the other), encrypted on the same row, before anything is
 * reported.
 */
final class StripeWebhookHealthService
{
    public const string ADD_EVENTS = 'add_events';

    public const string CREATE = 'create';

    /** Create a new endpoint to replace one that is gone or cannot be confirmed. */
    public const string RECONNECT = 'reconnect';

    public function __construct(
        private readonly WorkspacePaymentConnections $connections,
        private readonly StripeKeyAdapter $stripe,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /** Whether the row is a Stripe connection with a key of its own to check with. */
    public function checkable(WorkspacePaymentConnection $row): bool
    {
        return $row->processor === PaymentProcessor::Stripe->value
            && $row->isActive()
            && ! $row->isReused()
            && $row->secret() !== null;
    }

    /**
     * The URLs this connection's events are served at: its own, and for the
     * connection made from the deployment's environment also the legacy one.
     *
     * @return list<string>
     */
    public function expectedUrls(WorkspacePaymentConnection $row): array
    {
        $urls = [WebhookUrl::forConnection(PaymentProcessor::Stripe, $row->endpoint_token)];
        if ($row->origin === ConnectionOrigin::Environment->value) {
            $urls[] = WebhookUrl::legacy(PaymentProcessor::Stripe);
        }

        return $urls;
    }

    /**
     * Ask Stripe and record the answer. Never throws for a Stripe failure: that
     * is a result of its own. A row that cannot be checked is left alone.
     */
    public function check(WorkspacePaymentConnection $row): WebhookHealth
    {
        if (! $this->checkable($row)) {
            return new WebhookHealth(WebhookHealthStatus::Unreachable);
        }

        $health = $this->read($row);
        $this->record($row, $health);

        return $health;
    }

    /**
     * Check without throwing anything at all, for the places that must not fail
     * because of it (saving a connection): a failure is logged without the key.
     */
    public function checkQuietly(WorkspacePaymentConnection $row): void
    {
        try {
            $this->check($row);
        } catch (Throwable $failure) {
            Log::warning('The Stripe webhook check could not be recorded.', [
                'workspace_id' => $row->workspace_id,
                'exception' => $failure::class,
            ]);
        }
    }

    /**
     * Make the endpoint right, as the owner or admin chose in the dialog.
     *
     * `$action` is what the person confirmed. The state is read again first and
     * the action is carried out only when it is still what that state calls for,
     * so a stale page never changes something the person did not see.
     *
     * @return array{message: string, health: WebhookHealth}
     *
     * @throws AuthorizationException
     * @throws DomainException with a message that is safe to show
     */
    public function fix(Workspace $workspace, User $actor, string $action): array
    {
        if (! $this->connections->manages($actor, $workspace)) {
            throw new AuthorizationException('Only a workspace owner or admin fixes the Stripe webhook.');
        }
        $row = $this->connections->activeRow($workspace, PaymentProcessor::Stripe);
        if ($row === null || ! $this->checkable($row)) {
            throw new DomainException('This workspace has no Stripe key of its own to fix the webhook with.');
        }

        $health = $this->check($row);

        return match ($action) {
            self::ADD_EVENTS => $this->addEvents($row, $health),
            self::CREATE => $this->createEndpoint($row, [WebhookHealthStatus::NotFound], 'Created the Stripe webhook endpoint and stored its signing secret.', $health),
            self::RECONNECT => $this->createEndpoint($row, [WebhookHealthStatus::EndpointMissing, WebhookHealthStatus::EndpointUnverified], 'Created a new Stripe webhook endpoint and stored its id and signing secret together.', $health),
            default => throw new DomainException('Choose what to fix.'),
        };
    }

    /** @return array{message: string, health: WebhookHealth} */
    private function addEvents(WorkspacePaymentConnection $row, WebhookHealth $health): array
    {
        // Only the endpoint SVC created and holds the secret of: never one that merely shares its URL.
        if ($health->status !== WebhookHealthStatus::MissingEvents || $health->endpointId === null || $health->endpointId !== $row->webhook_endpoint_id) {
            throw new DomainException('The webhook changed since this page was loaded; it no longer lacks events. Check again.');
        }
        // The union of what the endpoint has and what it lacks: nothing is removed.
        $events = [...$health->subscribed, ...$health->missing];
        try {
            $this->stripe->setWebhookEvents((string) $row->secret(), $health->endpointId, $events);
        } catch (WebhookCallFailed $failed) {
            throw new DomainException($this->writeRefusal($failed->outcome));
        }

        $after = $this->check($row);

        return ['message' => $this->afterFix($after, 'Added '.count($health->missing).' missing '.(count($health->missing) === 1 ? 'event' : 'events').' to the Stripe webhook.'), 'health' => $after];
    }

    /**
     * @param  list<WebhookHealthStatus>  $allowed  the states this repair is for
     * @return array{message: string, health: WebhookHealth}
     */
    private function createEndpoint(WorkspacePaymentConnection $row, array $allowed, string $done, WebhookHealth $health): array
    {
        if (! in_array($health->status, $allowed, true)) {
            throw new DomainException('The webhook changed since this page was loaded; it no longer calls for this. Check again.');
        }
        $credentials = new PaymentCredentials((string) $row->secret(), environment: $row->environment);
        try {
            $registered = $this->stripe->registerWebhook($credentials, WebhookUrl::forConnection(PaymentProcessor::Stripe, $row->endpoint_token));
        } catch (PaymentCredentialsRejected) {
            throw new DomainException($this->writeRefusal(WebhookHealthStatus::PermissionDenied));
        }

        // The new signing secret replaces the old one on this row, encrypted by
        // the model's cast like every other secret. If it cannot be stored the
        // endpoint just made points nowhere, so it is deleted again.
        try {
            $stored = WorkspacePaymentConnection::query()
                ->where('workspace_id', $row->workspace_id)
                ->whereKey($row->id)
                ->whereNull('revoked_at')
                ->whereNull('reuses_connection_id')
                ->first();
            if ($stored === null) {
                throw new DomainException('The connection was removed or replaced meanwhile; nothing was changed.');
            }
            $stored->forceFill([
                'webhook_secret' => $registered->secret(),
                'webhook_endpoint_id' => $registered->endpointId,
            ])->save();
        } catch (Throwable $failure) {
            $this->stripe->removeWebhook($credentials, $registered->endpointId);

            throw $failure instanceof DomainException ? $failure : new DomainException('The new webhook could not be saved, so it was removed from Stripe. Try again.');
        } finally {
            $this->connections->forget();
        }

        $row = $this->connections->activeRow(Workspace::query()->findOrFail($row->workspace_id), PaymentProcessor::Stripe) ?? $row;
        $after = $this->check($row);

        return ['message' => $this->afterFix($after, $done), 'health' => $after];
    }

    private function afterFix(WebhookHealth $after, string $done): string
    {
        return $after->status === WebhookHealthStatus::Healthy
            ? $done
            : $done.' Stripe still reports a problem; see the webhook status.';
    }

    private function writeRefusal(WebhookHealthStatus $outcome): string
    {
        return match ($outcome) {
            WebhookHealthStatus::PermissionDenied => 'This restricted key cannot change webhook endpoints. In Stripe, edit the key, set Webhook Endpoints to Write, and try again.',
            WebhookHealthStatus::KeyRejected => 'Stripe did not accept the saved key. Replace the key, then try again.',
            WebhookHealthStatus::Healthy, WebhookHealthStatus::MissingEvents, WebhookHealthStatus::Disabled, WebhookHealthStatus::NotFound, WebhookHealthStatus::EndpointMissing, WebhookHealthStatus::EndpointUnverified, WebhookHealthStatus::ModeMismatch, WebhookHealthStatus::Unreachable => 'SVC could not reach Stripe to change the webhook. Nothing was changed; try again.',
        };
    }

    /** What Stripe says about the endpoint, without recording it. */
    private function read(WorkspacePaymentConnection $row): WebhookHealth
    {
        try {
            $endpoints = $this->stripe->webhookEndpoints((string) $row->secret());
        } catch (WebhookCallFailed $failed) {
            return new WebhookHealth($failed->outcome);
        }

        return StripeWebhookHealthDecision::decide(
            StripeWebhookService::REQUIRED_EVENTS,
            $this->expectedUrls($row),
            $row->environment,
            $row->webhook_endpoint_id,
            $endpoints,
        );
    }

    private function record(WorkspacePaymentConnection $row, WebhookHealth $health): void
    {
        $now = $this->clock->now()->utc();
        // Scoped to the row's own workspace, and to the same row only while it is active.
        WorkspacePaymentConnection::query()
            ->where('workspace_id', $row->workspace_id)
            ->whereKey($row->id)
            ->whereNull('revoked_at')
            ->update([
                'webhook_check_status' => $health->status->value,
                'webhook_check_missing' => json_encode($health->missing, JSON_THROW_ON_ERROR),
                'webhook_checked_at' => $now,
            ]);
        $this->connections->forget();
    }
}
