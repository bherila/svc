<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Services\AgentApi\AgentPackageMutationAction;
use App\Services\AgentApi\Operations\ServicePackageOperations;
use App\Services\Packages\ServicePackageReadService;
use App\Support\Packages\PackageStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The service package operations (docs/service-packages.md); every decision is the read service's or the workflow's. */
final class AgentPackageController extends Controller
{
    public function __construct(private readonly ServicePackageReadService $reads, private readonly AgentPackageMutationAction $writes, private readonly AgentMutationContextFactory $contexts) {}

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User || $actor instanceof AgentPrincipal, 401);
        $data = $request->validate([
            'status' => ['nullable', Rule::enum(PackageStatus::class)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.ServicePackageOperations::MAX_LIMIT],
            'cursor' => ['nullable', 'string', 'max:2048'],
        ]);

        return response()->json($this->reads->list($actor, $workspace, isset($data['status']) ? PackageStatus::from($data['status']) : null, (int) ($data['limit'] ?? 25), $data['cursor'] ?? null));
    }

    public function show(Request $request, Workspace $workspace, string $package): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User || $actor instanceof AgentPrincipal, 401);

        return response()->json(['data' => $this->reads->get($actor, $workspace, $package)]);
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        return $this->mutate($request, $workspace, 'packages.create', null, 201);
    }

    public function update(Request $request, Workspace $workspace, string $package): JsonResponse
    {
        return $this->mutate($request, $workspace, 'packages.update', $package);
    }

    public function publish(Request $request, Workspace $workspace, string $package): JsonResponse
    {
        return $this->mutate($request, $workspace, 'packages.publish', $package);
    }

    public function retire(Request $request, Workspace $workspace, string $package): JsonResponse
    {
        return $this->mutate($request, $workspace, 'packages.retire', $package);
    }

    private function mutate(Request $request, Workspace $workspace, string $operation, ?string $package, int $status = 200): JsonResponse
    {
        $context = $this->contexts->fromAuthenticatedClient($request);
        $result = $this->writes->run($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey, $operation, $package, $request->all());

        return response()->json(['data' => $this->reads->get($context->user, $workspace, $result->public_id)], $status);
    }
}
