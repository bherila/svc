<?php

namespace App\Services\AgentApi;

use App\Actions\CreateWorkspace;
use App\Models\AgentWorkspaceCreation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Support\AgentApi\AgentWriteCutover;
use App\Support\Concurrency\Locks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** A new tenant has no prior version; an actor/client/key reservation serializes creation. */
final class AgentWorkspaceCreationAction
{
    public function __construct(private readonly CreateWorkspace $create, private readonly AgentMutationExecutor $mutations, private readonly AgentAccess $access) {}

    /** @param array<string,mixed> $payload
     * @return array<string,mixed> */
    public function run(User $actor, string $clientId, string $key, array $payload): array
    {
        abort_unless(AgentWriteCutover::workspaces(), 404);
        Validator::make(['key' => $key], ['key' => ['required', 'string', 'max:255']])->validate();
        Validator::make(['body' => $payload], ['body' => ['required', 'array:name']])->validate();
        $data = Validator::make($payload, ['name' => ['required', 'string', 'max:120']])->validate();
        $digest = hash('sha256', json_encode(['name' => $data['name']], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $clientId, $key, $data, $digest): array {
            $reservation = AgentWorkspaceCreation::query()->where('user_id', $actor->id)
                ->where('oauth_client_id', $clientId)->where('idempotency_key', $key)
                ->tap(Locks::forUpdate())->createOrFirst([
                    'user_id' => $actor->id, 'oauth_client_id' => $clientId, 'idempotency_key' => $key,
                ], ['request_digest' => $digest]);
            abort_unless(hash_equals($reservation->request_digest, $digest), 409, 'The idempotency key was already used with a different request.');
            if ($reservation->created_workspace_id !== null) {
                $workspace = Workspace::query()->whereKey($reservation->created_workspace_id)->firstOrFail();
                abort_unless($this->access->isWorkspaceManager($actor, $workspace), 403);
            } else {
                $workspace = $this->create->handle($actor, $data['name']);
                $reservation->forceFill(['created_workspace_id' => $workspace->id])->save();
            }
            $this->mutations->run($actor, $workspace, $clientId, 'workspaces.create', $key, $data,
                fn (): array => [$workspace->public_id], function (array $ids) use ($actor, $workspace): void {
                    abort_unless(AgentWriteCutover::workspaces(), 404);
                    abort_unless($this->access->isWorkspaceManager($actor, $workspace), 403);
                });

            return ['data' => ['id' => $workspace->public_id, 'name' => $workspace->name,
                'web_url' => rtrim((string) config('app.url'), '/').route('workspaces.operations', $workspace, false)]];
        });
    }
}
