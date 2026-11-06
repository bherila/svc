<?php

namespace App\Services\Payments;

use App\Models\Workspace;
use App\Models\WorkspacePaymentConnection;
use App\Support\Payments\ConnectionOrigin;
use App\Support\Payments\PaymentProcessor;

/**
 * Which credentials a workspace's payments use.
 *
 * The answer comes from the workspace's own connection. The deployment's
 * environment credentials answer only until the migration command has turned
 * them into a connection, and only for a workspace that has never had one: a
 * workspace that revoked its connection gets nothing, not the environment.
 */
final class PaymentConnectionResolver
{
    public function __construct(private readonly WorkspacePaymentConnections $connections) {}

    /** The key for this workspace's Stripe account, or null when it has none. */
    public function stripe(Workspace $workspace): ?StripeAccess
    {
        $row = $this->connections->active($workspace, PaymentProcessor::Stripe);
        if ($row !== null) {
            return $this->stripeAccessOf($row);
        }
        if ($this->environmentMayServe($workspace, PaymentProcessor::Stripe)) {
            return $this->stripeEnvironment();
        }

        return null;
    }

    /** The key behind a connection (its own, or the one it borrows). */
    public function stripeAccessOf(WorkspacePaymentConnection $connection): ?StripeAccess
    {
        $secret = $this->connections->credentialSource($connection)?->secret();

        return $secret === null ? null : StripeAccess::forKey($secret);
    }

    /** The deployment's own key, as today's environment path uses it. */
    public function stripeEnvironment(): ?StripeAccess
    {
        $value = config('services.stripe.secret_key');

        return is_string($value) && $value !== '' ? StripeAccess::forKey($value) : null;
    }

    /**
     * Where events delivered to a connection's endpoint may land: its own
     * workspace and the workspaces that borrow it, read back with its key.
     */
    public function stripeRoute(WorkspacePaymentConnection $source): StripeEventRoute
    {
        $ids = [(int) $source->workspace_id];
        foreach ($this->connections->dependents($source) as $dependent) {
            $ids[] = (int) $dependent->workspace_id;
        }

        return new StripeEventRoute($this->stripeAccessOf($source), array_values(array_unique($ids)));
    }

    /**
     * The legacy, unauthenticated-by-token route: the environment's one
     * account, serving every workspace, until the migration command has run.
     */
    public function legacyStripeRoute(): StripeEventRoute
    {
        return new StripeEventRoute($this->stripeEnvironment(), null);
    }

    /**
     * Every Stripe account the deployment reads for its workspaces, with the
     * workspaces it serves: one entry per self-standing connection, for the
     * commands that walk payouts. The legacy environment account is listed
     * only until the migration command has run.
     *
     * @return list<StripeEventRoute>
     */
    public function stripeTargets(): array
    {
        $targets = [];
        foreach ($this->connections->allActive(PaymentProcessor::Stripe) as $row) {
            if ($row->isReused()) {
                continue;
            }
            $route = $this->stripeRoute($row);
            if ($route->access !== null) {
                $targets[] = $route;
            }
        }
        if (! $this->connections->environmentMigrated(PaymentProcessor::Stripe)) {
            $legacy = $this->legacyStripeRoute();
            if ($legacy->access !== null) {
                $targets[] = $legacy;
            }
        }

        return $targets;
    }

    /**
     * Whether the deployment's environment credentials may still serve this
     * workspace: the migration command has not run for the processor, and the
     * workspace has never connected one of its own.
     */
    public function environmentMayServe(Workspace $workspace, PaymentProcessor $processor): bool
    {
        return ! $this->connections->everConnected($workspace, $processor)
            && ! $this->connections->environmentMigrated($processor);
    }

    /**
     * The publishable key that belongs to the account this workspace's payment
     * intents are created on, or null when none is known (fail closed).
     *
     * - A workspace served by the environment credentials, with or without a
     *   connection row made from them, uses the deployment's key.
     * - A connection of its own uses the key stored on it; a connection that
     *   borrows another uses the source's key, never its own row's.
     * - A connection without a key, or whose source is gone, has none. The
     *   deployment's key is never substituted for a key of another account.
     */
    public function stripePublishableKey(Workspace $workspace): ?string
    {
        $row = $this->connections->activeRow($workspace, PaymentProcessor::Stripe);
        if ($row !== null) {
            $source = $this->connections->credentialSource($row);
            if ($source === null) {
                return null;
            }

            return $source->originEnum() === ConnectionOrigin::Environment
                ? $this->stripeEnvironmentPublishableKey()
                : $this->nonEmpty($source->publishable_key);
        }

        return $this->environmentMayServe($workspace, PaymentProcessor::Stripe) && $this->stripeEnvironment() !== null
            ? $this->stripeEnvironmentPublishableKey()
            : null;
    }

    /** The deployment's own publishable key. */
    public function stripeEnvironmentPublishableKey(): ?string
    {
        return $this->nonEmpty(config('services.stripe.publishable_key'));
    }

    private function nonEmpty(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
