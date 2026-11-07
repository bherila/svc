<?php

namespace App\Services\Payments;

use App\Models\Workspace;
use App\Support\Payments\PaymentProcessor;
use App\Support\WorkspaceClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Whether Stripe has lately refused a workspace's own key or account (#641).
 *
 * One marker per workspace, `workspaces.stripe_refused_at`, set when an
 * autopay charge is refused because of the connection. It stands until the
 * connection is saved again (a `connected_at` after the marker) or Stripe next
 * answers an ordinary call, and while it stands the scheduled command leaves
 * the workspace's autopay charges alone for {@see BACKOFF_HOURS}, so a broken
 * key is not hammered every fifteen minutes. The warning shown to the provider
 * does not expire with the backoff: only a fix or a working answer ends it.
 */
final class StripeConnectionHealth
{
    /** How long a refused connection is left alone before autopay tries it again. */
    public const int BACKOFF_HOURS = 6;

    public function __construct(
        private readonly WorkspacePaymentConnections $connections,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /** When Stripe refused the connection and nothing has fixed it since, or null. */
    public function refusedAt(Workspace $workspace): ?CarbonImmutable
    {
        $stored = DB::table('workspaces')->where('id', $workspace->id)->value('stripe_refused_at');
        if (! is_string($stored) || $stored === '') {
            return null;
        }
        $refused = CarbonImmutable::parse($stored, 'UTC');
        $saved = $this->connections->activeRow($workspace, PaymentProcessor::Stripe)?->connected_at;

        return $saved !== null && $saved->utc()->greaterThan($refused) ? null : $refused;
    }

    /** Whether autopay should leave this workspace alone for now. */
    public function pauses(Workspace $workspace): bool
    {
        $refused = $this->refusedAt($workspace);

        return $refused !== null
            && $refused->addHours(self::BACKOFF_HOURS)->greaterThan($this->clock->now()->utc());
    }

    /** Note a refusal; true when the connection was not already marked as refused. */
    public function refuse(Workspace $workspace): bool
    {
        $fresh = $this->refusedAt($workspace) === null;
        DB::table('workspaces')->where('id', $workspace->id)->update(['stripe_refused_at' => $this->clock->now()->utc()]);

        return $fresh;
    }

    /** Stripe accepted the key: whatever was marked is over. */
    public function accepted(Workspace $workspace): void
    {
        DB::table('workspaces')->where('id', $workspace->id)->whereNotNull('stripe_refused_at')->update(['stripe_refused_at' => null]);
    }
}
