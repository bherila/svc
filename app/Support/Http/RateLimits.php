<?php

namespace App\Support\Http;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Support\Access\StaffRole;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * The named limiters for routes a person or a browser reaches without the agent API's own
 * throttle. Every ceiling is per minute and generous - several times what a person doing
 * ordinary work reaches - so the limiter exists to stop loops and probing, never to slow a
 * working screen. Each is tunable through `svc.rate_limits` without a code change.
 *
 * Processor webhooks (Stripe, Square) are deliberately absent: they are signature-verified,
 * the processor retries on failure, and a 429 would drop or delay a genuine delivery.
 */
final class RateLimits
{
    /** Name => default attempts per minute. */
    public const array DEFAULTS = [
        'sign-in' => 60,
        'portal' => 300,
        'portal-writes' => 60,
        'payment-intents' => 30,
        'invitations' => 30,
        'csp-reports' => 120,
        // Public service-package pages, read without signing in; per address.
        'public-packages' => 120,
        // A person's own REST and MCP credentials: listing, issuing, revoking and their settings.
        'credentials' => 30,
        // Browser-only writes that are not agent operations (preferences, shared-project
        // participation, reviewer grants, cross-workspace approvals, signing an agreement, the
        // edition switch). Per person.
        'web-writes' => 60,
    ];

    /**
     * The agent API's one outer throttle, as `throttle`'s own parameters: attempts, minutes and
     * bucket prefix. REST (through ThrottleOuterAgentRequests) and MCP share it on purpose, so a
     * principal has one budget whichever transport it uses (#459). The prefix keeps that budget
     * apart from every other numeric throttle keyed on the same person: an agent principal's id
     * is its user's id, so without one a browser search spent the agent's allowance.
     */
    public const string AGENT_API_THROTTLE = '60,1,agent-api';

    /**
     * Name => default attempts per minute, for routes whose every request does work a person
     * cannot see the cost of: a synchronous call to Stripe or Square with a pasted key, or a
     * document rendered in the request. These are deliberately lower than {@see DEFAULTS} -
     * entering keys or downloading a PDF is a handful of requests, not a screen's worth. The
     * revenue screens are reloaded far more often, so theirs is higher.
     */
    public const array COSTLY = [
        // Saving, reusing and checking processor keys and webhooks. Per person, and per workspace
        // for its owners and admins, so neither several workspaces nor several admins multiply
        // the calls one processor account sees.
        'payment-keys' => 5,
        // Invoice PDFs, the pro forma (a rolled-back cadence draft) and timesheet exports. Per person.
        'documents' => 10,
        // The Dashboard and client roster, which project every agreement's revenue on each load
        // (twice, with a deferred prop). Per person; a moderate ceiling, not a cache.
        'revenue' => 60,
    ];

    /** The {@see COSTLY} limiters that also carry a bucket for the route's workspace, drawn on by its managers. */
    private const array PER_WORKSPACE = ['payment-keys'];

    public static function register(): void
    {
        foreach (array_keys(self::DEFAULTS) as $name) {
            RateLimiter::for($name, static fn (Request $request): Limit => Limit::perMinute(self::attempts($name))
                ->by($name.'|'.self::subject($request, $name)));
        }
        foreach (array_keys(self::COSTLY) as $name) {
            RateLimiter::for($name, static function (Request $request) use ($name): array {
                $limits = [Limit::perMinute(self::attempts($name))->by($name.'|'.self::subject($request, $name))];
                $workspace = in_array($name, self::PER_WORKSPACE, true) ? self::managedWorkspace($request) : null;
                if ($workspace !== null) {
                    $limits[] = Limit::perMinute(self::attempts($name))->by($name.'|workspace:'.$workspace);
                }

                return $limits;
            });
        }
    }

    public static function attempts(string $name): int
    {
        $configured = config('svc.rate_limits.'.$name);

        $attempts = (int) $configured;

        return is_numeric($configured) && $attempts > 0 ? $attempts : (self::DEFAULTS[$name] ?? self::COSTLY[$name]);
    }

    /**
     * The route's workspace, when the signed-in person manages it.
     *
     * The limiter runs before route binding and authorization, so the workspace is still the
     * key from the address. Only its owners and admins draw on its bucket: anyone else would
     * otherwise lock its admins out of their own keys by posting to the address. Everyone
     * still draws on their own per-person bucket.
     */
    private static function managedWorkspace(Request $request): ?string
    {
        $user = $request->user();
        $workspace = $request->route('workspace');
        if (! $user instanceof User) {
            return null;
        }
        $id = match (true) {
            $workspace instanceof Workspace => $workspace->id,
            is_string($workspace) && Str::isUuid($workspace) => Workspace::query()->where('public_id', $workspace)->value('id'),
            default => null,
        };
        if ($id === null) {
            return null;
        }
        $role = WorkspaceMembership::query()->where('workspace_id', $id)->where('user_id', $user->id)->value('role');

        return is_string($role) && StaffRole::tryFrom($role)?->managesWorkspace() === true ? (string) $id : null;
    }

    /** The signed-in person when there is one, otherwise the address. Sign-in is always per address. */
    private static function subject(Request $request, string $name): string
    {
        $user = $name === 'sign-in' ? null : $request->user();

        return $user !== null ? 'user:'.$user->getAuthIdentifier() : 'ip:'.($request->ip() ?? 'unknown');
    }
}
