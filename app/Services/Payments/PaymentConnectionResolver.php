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
     * Whether this workspace's Stripe calls are made with the deployment's
     * environment account: it has no connection and the environment may serve
     * it, or its active connection is the environment one or borrows it.
     *
     * Only then does the deployment's publishable key belong to the account
     * that creates a payment intent. A workspace on a key of its own would be
     * handed a client secret its browser cannot confirm with that key.
     */
    public function stripeServedByEnvironment(Workspace $workspace): bool
    {
        $row = $this->connections->activeRow($workspace, PaymentProcessor::Stripe);
        if ($row !== null) {
            $source = $this->connections->credentialSource($row);

            return $source !== null && $source->originEnum() === ConnectionOrigin::Environment;
        }

        return $this->environmentMayServe($workspace, PaymentProcessor::Stripe) && $this->stripeEnvironment() !== null;
    }
}
