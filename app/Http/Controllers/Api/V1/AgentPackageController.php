<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Services\AgentApi\AgentPackageMutationAction;
use App\Services\AgentApi\Operations\ServicePackageOperations;
use App\Services\Packages\PackageRequestReadService;
use App\Services\Packages\ServicePackageReadService;
use App\Support\Packages\PackageRequestStatus;
use App\Support\Packages\PackageStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The service package and sign-up request operations
 * (docs/service-packages.md); every decision is the read services' or the
 * workflows'.
 */
final class AgentPackageController extends Controller
{
    public function __construct(
        private readonly ServicePackageReadService $reads,
        private readonly PackageRequestReadService $requestReads,
        private readonly AgentPackageMutationAction $writes,
        private readonly AgentMutationContextFactory $contexts,
    ) {}

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        [$actor, $status, $limit, $cursor] = $this->listing($request, PackageStatus::class);

        return response()->json($this->reads->list($actor, $workspace, $status, $limit, $cursor));
    }

    public function show(Request $request, Workspace $workspace, string $package): JsonResponse
    {
        return response()->json(['data' => $this->reads->get($this->reader($request), $workspace, $package)]);
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

    public function requests(Request $request, Workspace $workspace): JsonResponse
    {
        [$actor, $status, $limit, $cursor] = $this->listing($request, PackageRequestStatus::class);

        return response()->json($this->requestReads->list($actor, $workspace, $status, $limit, $cursor));
    }

    public function showRequest(Request $request, Workspace $workspace, string $packageRequest): JsonResponse
    {
        return response()->json(['data' => $this->requestReads->get($this->reader($request), $workspace, $packageRequest)]);
    }

    /** The approved request, and the enrollment it became (never the invitation: ADM-5). */
    public function approveRequest(Request $request, Workspace $workspace, string $packageRequest): JsonResponse
    {
        $user = $this->decide($request, $workspace, 'package_requests.approve', $packageRequest);

        return response()->json([
            'data' => $this->requestReads->get($user, $workspace, $packageRequest),
            'enrollment' => $this->requestReads->enrollment($user, $workspace, $packageRequest),
        ]);
    }

    public function declineRequest(Request $request, Workspace $workspace, string $packageRequest): JsonResponse
    {
        $user = $this->decide($request, $workspace, 'package_requests.decline', $packageRequest);

        return response()->json(['data' => $this->requestReads->get($user, $workspace, $packageRequest)]);
    }

    public function eraseRequest(Request $request, Workspace $workspace, string $packageRequest): JsonResponse
    {
        $user = $this->decide($request, $workspace, 'package_requests.erase', $packageRequest);

        return response()->json(['data' => $this->requestReads->get($user, $workspace, $packageRequest)]);
    }

    private function mutate(Request $request, Workspace $workspace, string $operation, ?string $package, int $status = 200): JsonResponse
    {
        $context = $this->contexts->fromAuthenticatedClient($request);
        $result = $this->writes->run($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey, $operation, $package, $request->all());

        return response()->json(['data' => $this->reads->get($context->user, $workspace, $result->public_id)], $status);
    }

    private function decide(Request $request, Workspace $workspace, string $operation, string $packageRequest): User
    {
        $context = $this->contexts->fromAuthenticatedClient($request);
        $this->writes->decide($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey, $operation, $packageRequest, $request->all());

        return $context->user;
    }

    private function reader(Request $request): User|AgentPrincipal
    {
        $actor = $request->user();
        abort_unless($actor instanceof User || $actor instanceof AgentPrincipal, 401);

        return $actor;
    }

    /**
     * A list's caller and its validated filters.
     *
     * @template T of PackageStatus|PackageRequestStatus
     *
     * @param  class-string<T>  $statuses
     * @return array{User|AgentPrincipal, T|null, int, string|null}
     */
    private function listing(Request $request, string $statuses): array
    {
        $actor = $this->reader($request);
        $data = $request->validate([
            'status' => ['nullable', Rule::enum($statuses)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.ServicePackageOperations::MAX_LIMIT],
            'cursor' => ['nullable', 'string', 'max:2048'],
        ]);
        /** @var T|null $status */
        $status = isset($data['status']) ? $statuses::from($data['status']) : null;

        return [$actor, $status, (int) ($data['limit'] ?? 25), $data['cursor'] ?? null];
    }
}
