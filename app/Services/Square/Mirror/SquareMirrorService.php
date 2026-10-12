<?php

namespace App\Services\Square\Mirror;

use App\Models\SquareSyncRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentMutationExecutor;
use App\Services\Authorization\AgentAccess;
use App\Services\Square\SquareConfiguration;
use App\Services\Square\SquareRequestFailed;
use Illuminate\Cache\RateLimiter;

/**
 * The Square mirror for workspace managers (#438), shared by REST, MCP and
 * the operator screen.
 *
 * A requested sync is an agent mutation: its receipt is written with the
 * run, so a retried request returns the same run instead of starting
 * another. The run itself is performed after that transaction commits - no
 * transaction is ever held open across Square calls - and only once.
 */
final class SquareMirrorService
{
    /** Manual syncs per workspace in {@see SYNC_WINDOW_SECONDS}; the schedule is not counted. */
    public const int SYNCS_PER_WINDOW = 4;

    public const int SYNC_WINDOW_SECONDS = 600;

    public function __construct(
        private readonly AgentMutationExecutor $mutations,
        private readonly SquareMirrorSync $sync,
        private readonly AgentAccess $access,
        private readonly RateLimiter $limiter,
    ) {}

    public function sync(User $user, Workspace $workspace, string $clientId, string $idempotencyKey): SquareSyncRun
    {
        $this->admit($user, $workspace);
        $ids = $this->mutations->run(
            $user,
            $workspace,
            $clientId,
            'square.sync',
            $idempotencyKey,
            [],
            function () use ($user, $workspace): array {
                $key = 'square-sync:'.$workspace->getKey();
                if (! $this->limiter->attempt($key, self::SYNCS_PER_WINDOW, static fn (): bool => true, self::SYNC_WINDOW_SECONDS)) {
                    $seconds = max(1, $this->limiter->availableIn($key));
                    abort(429, "Square was synced recently. Retry in {$seconds} seconds.", ['Retry-After' => (string) $seconds]);
                }

                return [$this->sync->request($workspace, SquareSyncRun::TRIGGER_REQUEST, $user)->public_id];
            },
            fn (array $ids) => $this->admit($user, $workspace),
        );
        $run = SquareSyncRun::query()->where('workspace_id', $workspace->id)->where('public_id', $ids[0] ?? '')->firstOrFail();

        // A replay finds its run already performed, or being performed, and
        // returns it as it stands.
        return $this->sync->perform($workspace, $run);
    }

    private function admit(User $user, Workspace $workspace): void
    {
        abort_unless($this->access->isWorkspaceManager($user, $workspace), 403, 'The Square mirror is available to workspace managers only.');
        if (! SquareConfiguration::fromConfig()->servesWorkspace($workspace)) {
            throw SquareRequestFailed::notConnected();
        }
    }
}
