<?php

namespace App\Services\Payments;

use App\Models\Workspace;
use App\Support\Concurrency\Locks;
use App\Support\Payments\PaymentProcessor;
use App\Support\Payments\StripeCredentialIdentity;
use App\Support\Payments\StripeHealthRefusal;
use App\Support\WorkspaceClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Whether Stripe has lately refused a workspace's own key or account (#641).
 *
 * One marker per workspace, `workspaces.stripe_refused_at`, set when an
 * autopay charge is refused because of the connection. It stands until the
 * connection is saved again or that same effective key next
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
        $stored = DB::table('workspaces')->where('id', $workspace->id)
            ->first(['stripe_refused_at', 'stripe_refused_credential_version', 'stripe_credential_version']);
        if ($stored === null || ! is_string($stored->stripe_refused_at) || $stored->stripe_refused_at === '') {
            return null;
        }
        $refused = CarbonImmutable::parse($stored->stripe_refused_at, 'UTC');
        if ($stored->stripe_refused_credential_version !== null) {
            $version = $stored->stripe_credential_version;
            if ($version === null) {
                $secret = config('services.stripe.secret_key');
                if (! is_string($secret) || $secret === '') {
                    return null;
                }
                $version = StripeCredentialIdentity::environment($workspace->id, $secret)->healthVersion();
            }

            return $stored->stripe_refused_credential_version === $version ? $refused : null;
        }
        // Timestamp-only legacy observations predate credential attribution.
        // Keep their conservative warning/backoff until a repair or fresh answer.
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

    /** Note a current key's refusal; true only for the first notice of this breakage. */
    public function refuse(Workspace $workspace, StripeAccess $access): bool
    {
        return $this->recordRefusal($workspace, $access) === StripeHealthRefusal::First;
    }

    public function recordRefusal(Workspace $workspace, StripeAccess $access): StripeHealthRefusal
    {
        return match ($this->apply($workspace, $access, refused: true)) {
            null => StripeHealthRefusal::Obsolete,
            true => StripeHealthRefusal::First,
            false => StripeHealthRefusal::Repeated,
        };
    }

    /** A working answer may clear only the key that actually answered. */
    public function accepted(Workspace $workspace, StripeAccess $access): void
    {
        $this->apply($workspace, $access, refused: false);
    }

    private function apply(Workspace $workspace, StripeAccess $access, bool $refused): ?bool
    {
        $identity = $access->credentialIdentity;
        if ($identity === null || $identity->workspaceId !== $workspace->id) {
            return null;
        }

        return DB::transaction(function () use ($workspace, $identity, $refused): ?bool {
            // A locking read sees the current generation even when the caller's
            // REPEATABLE READ snapshot predates reconnect. Connection writers
            // save this fence and reset the marker in their own transaction.
            $current = DB::table('workspaces')->where('id', $workspace->id)->tap(Locks::forUpdate())
                ->first(['stripe_credential_version', 'stripe_refused_at', 'stripe_refused_credential_version']);
            if ($current === null || $current->stripe_credential_version !== $identity->connectionVersion()) {
                // Another request may have replaced the row without invalidating
                // this request's cache. The next charge must resolve B, not A.
                $this->connections->forgetRequest();

                return null;
            }
            if ($identity->connectionId === null) {
                $secret = config('services.stripe.secret_key');
                if (! is_string($secret) || $secret === '' || $identity->environmentVersion !== StripeCredentialIdentity::environment($workspace->id, $secret)->environmentVersion) {
                    return null;
                }
            }
            $version = $identity->healthVersion();
            $fresh = $current->stripe_refused_at === null
                || ($current->stripe_refused_credential_version !== null && $current->stripe_refused_credential_version !== $version);
            DB::table('workspaces')->where('id', $workspace->id)->update([
                'stripe_refused_at' => $refused ? $this->clock->now()->utc() : null,
                'stripe_refused_credential_version' => $refused ? $version : null,
            ]);

            return $refused && $fresh;
        });
    }
}
