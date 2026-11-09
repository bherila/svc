<?php

namespace App\Services\AgentApi;

use App\Models\ServicePackage;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\Operations\ServicePackageOperations;
use App\Services\Authorization\AgentAccess;
use App\Services\Packages\ServicePackageReadService;
use App\Services\Packages\ServicePackageWorkflow;
use App\Support\AgentApi\ExplicitConfirmation;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * The agent API's way into {@see ServicePackageWorkflow}: an idempotency
 * receipt, the closed body its contract publishes, and `confirm: true` for
 * publish and retire (API-2). Everything about the package itself -
 * authorization, term validation, locking, versions and refusals - is the
 * workflow's, exactly as for the website.
 */
final class AgentPackageMutationAction
{
    public function __construct(
        private readonly AgentMutationExecutor $mutations,
        private readonly ServicePackageWorkflow $workflow,
        private readonly ServicePackageReadService $reads,
        private readonly AgentAccess $access,
    ) {}

    /** @param array<string, mixed> $payload */
    public function run(User $actor, Workspace $workspace, string $clientId, string $key, string $operation, ?string $packageId, array $payload): ServicePackage
    {
        Validator::make(['key' => $key], ['key' => ['required', 'string', 'max:255']])->validate();
        $ids = $this->mutations->run($actor, $workspace, $clientId, $operation, $key, ['package_id' => $packageId, 'body' => $payload], function () use ($actor, $workspace, $operation, $packageId, $payload): array {
            // A caller who may not manage the workspace learns nothing about
            // which packages exist, before anything about the body (API-5).
            abort_unless($this->access->isWorkspaceManager($actor, $workspace), 404);
            Validator::make(['body' => $payload], ['body' => ['present', 'array:'.implode(',', ServicePackageOperations::bodyFields($operation))]])->validate();
            $version = $operation === 'packages.create' ? null : Validator::make($payload, ['expected_version' => ['required', 'string', 'size:64']])->validate()['expected_version'];
            if (in_array($operation, ['packages.publish', 'packages.retire'], true)) {
                Validator::make($payload, ['confirm' => ['required', new ExplicitConfirmation]])->validate();
            }
            $terms = array_diff_key($payload, array_flip(['expected_version', 'confirm']));
            $package = $packageId === null ? null : $this->reads->find($actor, $workspace, $packageId);

            $result = match ($operation) {
                'packages.create' => $this->workflow->create($actor, $workspace, $terms),
                'packages.update' => $this->workflow->update($actor, $workspace, $package ?? abort(404), $terms, $version),
                'packages.publish' => $this->workflow->publish($actor, $workspace, $package ?? abort(404), $version),
                'packages.retire' => $this->workflow->retire($actor, $workspace, $package ?? abort(404), $version),
                default => throw new InvalidArgumentException("Unsupported package mutation [{$operation}]."),
            };

            if ($operation === 'packages.publish') {
                // A first publish mints the workspace's catalog token with a
                // conditional update; read it back so the answer carries the
                // public URL it just opened.
                $workspace->refresh();
            }

            return [$result->public_id];
        }, function (array $ids) use ($actor, $workspace): void {
            // A replay is answered only to someone who may still manage it.
            $this->reads->find($actor, $workspace, (string) ($ids[0] ?? ''));
        });

        return $this->reads->find($actor, $workspace, (string) ($ids[0] ?? ''));
    }
}
