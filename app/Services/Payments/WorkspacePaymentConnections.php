<?php

namespace App\Services\Payments;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Models\WorkspacePaymentConnection;
use App\Support\Access\StaffRole;
use App\Support\Payments\ConnectionOrigin;
use App\Support\Payments\PaymentProcessor;
use App\Support\WorkspaceClock;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * The tenant-scoped boundary around `workspace_payment_connections`.
 *
 * Every read and write names the workspace. The only deliberate exceptions are
 * the lookup that routes an inbound webhook by its URL's opaque token
 * ({@see activeByEndpoint()}) and the revocation cascade to connections that
 * borrowed a revoked one: neither returns a row to any caller that has not
 * already been given the token and passed a signature check, and the
 * cascade writes each dependent under its own workspace.
 */
final class WorkspacePaymentConnections
{
    private const string ANY_KEY = 'payments:any-active:';

    /**
     * A workspace's rows at a processor, read once per request. The service is
     * scoped to the request and every write here clears this, so a page that asks
     * several times (navigation, flags, a presenter) costs one query.
     *
     * @var array<string, Collection<int, WorkspacePaymentConnection>>
     */
    private array $rows = [];

    /** @var array<string, bool> */
    private array $migrated = [];

    public function __construct(private readonly WorkspaceClock $clock = new WorkspaceClock) {}

    /** Drop what this request has read; for anything that changes connections without this service. */
    public function forget(): void
    {
        $this->rows = [];
        $this->migrated = [];
        foreach (PaymentProcessor::cases() as $processor) {
            Cache::forget(self::ANY_KEY.$processor->value);
        }
    }

    /**
     * Whether any workspace on the deployment has an active connection to the
     * processor. A cheap, briefly cached answer for pages that only decide
     * whether to look further (the navigation, the deployment flags); a write
     * through this service clears it, and anything that acts for a workspace
     * still asks {@see active()}.
     */
    public function anyActive(PaymentProcessor $processor): bool
    {
        return (bool) Cache::remember(self::ANY_KEY.$processor->value, 60, fn (): bool => WorkspacePaymentConnection::query()
            ->where('processor', $processor->value)
            ->whereNull('revoked_at')
            ->exists());
    }

    /**
     * Keep `workspaces.has_square_connection` in step with the rows, so the
     * navigation can skip the lookup for the workspaces that have none.
     */
    private function syncSquareHint(int $workspaceId): void
    {
        $has = WorkspacePaymentConnection::query()
            ->where('workspace_id', $workspaceId)
            ->where('processor', PaymentProcessor::Square->value)
            ->whereNull('revoked_at')
            ->exists();
        DB::table('workspaces')->where('id', $workspaceId)->update(['has_square_connection' => $has]);
    }

    /** @return Collection<int, WorkspacePaymentConnection> */
    private function rowsFor(Workspace $workspace, PaymentProcessor $processor): Collection
    {
        return $this->rows[$workspace->id.':'.$processor->value] ??= WorkspacePaymentConnection::query()
            ->where('workspace_id', $workspace->id)
            ->where('processor', $processor->value)
            ->get();
    }

    /** Whether the workspace has ever had a connection at this processor, revoked or not. */
    public function everConnected(Workspace $workspace, PaymentProcessor $processor): bool
    {
        return $this->rowsFor($workspace, $processor)->isNotEmpty();
    }

    /**
     * Whether any connection was made from the deployment's environment
     * credentials. Once one exists the migration command has run, and the
     * environment path is retired for that processor everywhere.
     */
    public function environmentMigrated(PaymentProcessor $processor): bool
    {
        return $this->migrated[$processor->value] ??= WorkspacePaymentConnection::query()
            ->where('processor', $processor->value)
            ->where('origin', ConnectionOrigin::Environment->value)
            ->exists();
    }

    /** The workspace's own active row, whether or not the credentials behind it still work. */
    public function activeRow(Workspace $workspace, PaymentProcessor $processor): ?WorkspacePaymentConnection
    {
        return $this->rowsFor($workspace, $processor)->first(fn (WorkspacePaymentConnection $row): bool => $row->isActive());
    }

    /**
     * The workspace's active connection, or null when it has none or the one it
     * borrows has been revoked or has gone.
     */
    public function active(Workspace $workspace, PaymentProcessor $processor): ?WorkspacePaymentConnection
    {
        $row = $this->activeRow($workspace, $processor);

        return $row !== null && $this->credentialSource($row) !== null ? $row : null;
    }

    /**
     * The row whose account and credentials stand behind this one: itself, or
     * the active connection it borrows. Null when the borrowed one is no longer
     * active, so revoking a source stops everything that reuses it at once.
     */
    public function credentialSource(WorkspacePaymentConnection $row): ?WorkspacePaymentConnection
    {
        if (! $row->isActive()) {
            return null;
        }
        if (! $row->isReused()) {
            return $row;
        }
        // The one deliberate cross-workspace read: the id was written by this
        // service when the owner reused the connection, and the source must
        // still be an active row of the same processor and account.
        $source = WorkspacePaymentConnection::query()
            ->whereKey($row->reuses_connection_id)
            ->where('processor', $row->processor)
            ->where('external_account_id', $row->external_account_id)
            ->whereNull('revoked_at')
            ->first();

        return $source !== null && ! $source->isReused() ? $source : null;
    }

    /**
     * The active, self-standing connection whose webhook URL carries this
     * token. A reused connection receives nothing at its own URL: its source's
     * endpoint serves every workspace that borrows it. Unknown, revoked and
     * borrowed tokens all answer null.
     */
    public function activeByEndpoint(PaymentProcessor $processor, #[SensitiveParameter] string $token): ?WorkspacePaymentConnection
    {
        if (preg_match('/^[A-Za-z0-9]{40}$/D', $token) !== 1) {
            return null;
        }

        return WorkspacePaymentConnection::query()
            ->where('processor', $processor->value)
            ->where('endpoint_token', $token)
            ->whereNull('revoked_at')
            ->whereNull('reuses_connection_id')
            ->first();
    }

    /**
     * Active connections that borrow this one.
     *
     * @return Collection<int, WorkspacePaymentConnection>
     */
    public function dependents(WorkspacePaymentConnection $source): Collection
    {
        return WorkspacePaymentConnection::query()
            ->where('reuses_connection_id', $source->id)
            ->whereNull('revoked_at')
            ->get();
    }

    /** The one connection made from the environment credentials, if the migration command has run. */
    public function environmentConnection(PaymentProcessor $processor): ?WorkspacePaymentConnection
    {
        return WorkspacePaymentConnection::query()
            ->where('processor', $processor->value)
            ->where('origin', ConnectionOrigin::Environment->value)
            ->whereNull('revoked_at')
            ->first();
    }

    /**
     * Every active connection of a processor, one row per workspace, for the
     * commands that walk workspaces.
     *
     * @return Collection<int, WorkspacePaymentConnection>
     */
    public function allActive(PaymentProcessor $processor): Collection
    {
        return WorkspacePaymentConnection::query()
            ->where('processor', $processor->value)
            ->whereNull('revoked_at')
            ->orderBy('workspace_id')
            ->get()
            ->filter(fn (WorkspacePaymentConnection $row): bool => $this->credentialSource($row) !== null)
            ->values();
    }

    /**
     * Record a connection whose credentials were entered (or copied from the
     * environment). A workspace has at most one active connection per
     * processor: connecting again replaces the earlier one, which is revoked
     * locally in the same transaction.
     *
     * @param  array{external_account_id: string, display_name?: string|null, environment?: string|null, secret: string, webhook_secret?: string|null, webhook_endpoint_id?: string|null, location_id?: string|null, endpoint_token?: string}  $account
     */
    public function connect(Workspace $workspace, PaymentProcessor $processor, ConnectionOrigin $origin, ?User $actor, #[SensitiveParameter] array $account): WorkspacePaymentConnection
    {
        if ($origin === ConnectionOrigin::Reused) {
            throw new DomainException('A reused connection is made with reuse().');
        }

        $this->forget();

        try {
            return DB::transaction(function () use ($workspace, $processor, $origin, $actor, $account): WorkspacePaymentConnection {
                $this->retireActive($workspace, $processor, $actor);

                $created = $this->insert($workspace, $processor, $origin, $actor, $account);
                $this->syncSquareHint($workspace->id);

                return $created;
            });
        } finally {
            $this->forget();
        }
    }

    /**
     * Let `$target` use the account `$source` is connected to, without a second
     * key entry. Both workspaces must have the same owner and the actor must
     * manage both; the new row borrows the source's credentials rather than
     * copying them, so revoking the source stops every workspace that reuses it.
     */
    public function reuse(Workspace $target, WorkspacePaymentConnection $source, User $actor): WorkspacePaymentConnection
    {
        $origin = $this->credentialSource($source);
        if ($origin === null || $source->workspace_id === $target->id) {
            throw new DomainException('That connection cannot be reused.');
        }
        $sourceWorkspace = Workspace::query()->whereKey($source->workspace_id)->firstOrFail();
        if (! $this->manages($actor, $target) || ! $this->manages($actor, $sourceWorkspace) || ! $this->shareOwner($target, $sourceWorkspace)) {
            throw new DomainException('A connection can be reused only between workspaces with the same owner.');
        }
        $processor = $origin->processorEnum();
        $this->forget();

        try {
            return DB::transaction(function () use ($target, $origin, $processor, $actor): WorkspacePaymentConnection {
                $this->retireActive($target, $processor, $actor);

                $created = WorkspacePaymentConnection::query()->create([
                    'workspace_id' => $target->id,
                    'processor' => $processor->value,
                    'external_account_id' => $origin->external_account_id,
                    'display_name' => $origin->display_name,
                    'environment' => $origin->environment,
                    'origin' => ConnectionOrigin::Reused->value,
                    'reuses_connection_id' => $origin->id,
                    'endpoint_token' => Str::random(40),
                    'connected_by_user_id' => $actor->id,
                    'connected_at' => $this->clock->now(),
                    'active_slot' => $processor->value,
                ]);
                $this->syncSquareHint($target->id);

                return $created;
            });
        } finally {
            $this->forget();
        }
    }

    /**
     * Revoke a connection, and every connection that borrows it. Returns true
     * when this call revoked it. Whether the processor is also told is the
     * caller's decision, after the transaction commits.
     */
    public function revoke(WorkspacePaymentConnection $connection, ?User $actor): bool
    {
        $this->forget();

        return DB::transaction(function () use ($connection, $actor): bool {
            $revoked = $this->markRevoked($connection, $actor);
            if (! $revoked) {
                return false;
            }
            $dependents = WorkspacePaymentConnection::query()
                ->where('reuses_connection_id', $connection->id)
                ->whereNull('revoked_at')
                ->get();
            foreach ($dependents as $dependent) {
                $this->markRevoked($dependent, $actor);
            }

            return true;
        });
    }

    /** Whether any other active connection still stands behind this processor account. */
    public function accountStillConnected(WorkspacePaymentConnection $revoked): bool
    {
        return WorkspacePaymentConnection::query()
            ->where('processor', $revoked->processor)
            ->where('external_account_id', $revoked->external_account_id)
            ->whereNull('revoked_at')
            ->whereKeyNot($revoked->id)
            ->exists();
    }

    /**
     * Active connections elsewhere that `$actor` may reuse in `$target`: the
     * actor manages that workspace too, and it has the same owner as `$target`.
     *
     * @return Collection<int, WorkspacePaymentConnection>
     */
    public function reusableFor(Workspace $target, PaymentProcessor $processor, User $actor): Collection
    {
        if (! $this->manages($actor, $target)) {
            return new Collection;
        }
        $ownedTogether = $this->ownerIds($target);
        $managed = WorkspaceMembership::query()
            ->where('user_id', $actor->id)
            ->whereIn('role', [StaffRole::Owner->value, StaffRole::Admin->value])
            ->where('workspace_id', '!=', $target->id)
            ->pluck('workspace_id')
            ->all();
        if ($managed === [] || $ownedTogether === []) {
            return new Collection;
        }
        $sharing = WorkspaceMembership::query()
            ->whereIn('workspace_id', $managed)
            ->where('role', StaffRole::Owner->value)
            ->whereIn('user_id', $ownedTogether)
            ->pluck('workspace_id')
            ->all();

        return WorkspacePaymentConnection::query()
            ->whereIn('workspace_id', $sharing)
            ->where('processor', $processor->value)
            ->whereNull('revoked_at')
            ->whereNull('reuses_connection_id')
            ->orderBy('workspace_id')
            ->get()
            ->filter(fn (WorkspacePaymentConnection $row): bool => $this->credentialSource($row) !== null)
            ->values();
    }

    /** Whether the user is an owner or admin of the workspace. */
    public function manages(User $user, Workspace $workspace): bool
    {
        $role = WorkspaceMembership::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->value('role');

        return is_string($role) && StaffRole::tryFrom($role)?->managesWorkspace() === true;
    }

    /** Whether some user holds the `owner` role on both workspaces. */
    public function shareOwner(Workspace $a, Workspace $b): bool
    {
        return array_intersect($this->ownerIds($a), $this->ownerIds($b)) !== [];
    }

    /** @return list<int> */
    private function ownerIds(Workspace $workspace): array
    {
        $ids = WorkspaceMembership::query()
            ->where('workspace_id', $workspace->id)
            ->where('role', StaffRole::Owner->value)
            ->pluck('user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return array_values($ids);
    }

    private function retireActive(Workspace $workspace, PaymentProcessor $processor, ?User $actor): void
    {
        $existing = $this->activeRow($workspace, $processor);
        if ($existing !== null) {
            $this->revoke($existing, $actor);
        }
    }

    private function markRevoked(WorkspacePaymentConnection $connection, ?User $actor): bool
    {
        $updated = WorkspacePaymentConnection::query()
            ->where('workspace_id', $connection->workspace_id)
            ->whereKey($connection->id)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => $this->clock->now(),
                'revoked_by_user_id' => $actor?->id,
                'active_slot' => null,
                // A revoked row keeps no credential: nothing can use it again.
                'secret' => null,
                'webhook_secret' => null,
                'webhook_endpoint_id' => null,
                'updated_at' => $this->clock->now(),
            ]);
        $connection->refresh();
        $this->syncSquareHint((int) $connection->workspace_id);
        $this->forget();

        return $updated === 1;
    }

    /** @param  array<string, mixed>  $account */
    private function insert(Workspace $workspace, PaymentProcessor $processor, ConnectionOrigin $origin, ?User $actor, #[SensitiveParameter] array $account): WorkspacePaymentConnection
    {
        return WorkspacePaymentConnection::query()->create([
            'workspace_id' => $workspace->id,
            'processor' => $processor->value,
            'external_account_id' => $account['external_account_id'],
            'display_name' => isset($account['display_name']) ? mb_substr((string) $account['display_name'], 0, 160) : null,
            'environment' => $account['environment'] ?? null,
            'origin' => $origin->value,
            'endpoint_token' => $account['endpoint_token'] ?? Str::random(40),
            'secret' => $account['secret'],
            'secret_hint' => WorkspacePaymentConnection::hint($account['secret']),
            'webhook_secret' => $account['webhook_secret'] ?? null,
            'webhook_endpoint_id' => $account['webhook_endpoint_id'] ?? null,
            'location_id' => $account['location_id'] ?? null,
            'connected_by_user_id' => $actor?->id,
            'connected_at' => $this->clock->now(),
            'active_slot' => $processor->value,
        ]);
    }
}
