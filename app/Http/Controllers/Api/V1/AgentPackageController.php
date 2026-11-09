<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentAgreementReadService;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Services\AgentApi\AgentPackageMutationAction;
use App\Services\AgentApi\Operations\ServicePackageOperations;
use App\Services\Engagement\CancellationRequestReadService;
use App\Services\Packages\ClientServiceReadService;
use App\Services\Packages\PackageEnrollmentReadService;
use App\Services\Packages\PackageMigrationPlanner;
use App\Services\Packages\PackageMigrationReadService;
use App\Services\Packages\PackageRequestReadService;
use App\Services\Packages\ServicePackageReadService;
use App\Support\Engagement\Cancellation\CancellationRequestStatus;
use App\Support\Packages\EnrollmentStatus;
use App\Support\Packages\Migration\MigrationStatus;
use App\Support\Packages\PackageRequestStatus;
use App\Support\Packages\PackageStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The service package, sign-up request, enrollment, client service,
 * cancellation and version move operations (docs/service-packages.md); every decision is the
 * read services' or the workflows'.
 */
final class AgentPackageController extends Controller
{
    public function __construct(
        private readonly ServicePackageReadService $reads,
        private readonly PackageRequestReadService $requestReads,
        private readonly AgentPackageMutationAction $writes,
        private readonly AgentMutationContextFactory $contexts,
        private readonly PackageEnrollmentReadService $enrollmentReads,
        private readonly ClientServiceReadService $services,
        private readonly CancellationRequestReadService $cancellationReads,
        private readonly AgentAgreementReadService $agreements,
        private readonly PackageMigrationPlanner $planner,
        private readonly PackageMigrationReadService $migrations,
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

    public function enrollments(Request $request, Workspace $workspace): JsonResponse
    {
        [$actor, $status, $limit, $cursor] = $this->listing($request, EnrollmentStatus::class);

        return response()->json($this->enrollmentReads->list($actor, $workspace, $status, $limit, $cursor));
    }

    public function showEnrollment(Request $request, Workspace $workspace, string $enrollment): JsonResponse
    {
        return response()->json(['data' => $this->enrollmentReads->get($this->reader($request), $workspace, $enrollment)]);
    }

    public function activateEnrollment(Request $request, Workspace $workspace, string $enrollment): JsonResponse
    {
        $context = $this->contexts->fromAuthenticatedClient($request);
        $this->writes->activate($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey, $enrollment, $request->all());

        return response()->json(['data' => $this->enrollmentReads->get($context->user, $workspace, $enrollment)]);
    }

    /** A client's own services (API-5): the caller's companies and projects only. */
    public function clientServices(Request $request, Workspace $workspace): JsonResponse
    {
        $actor = $this->reader($request);
        $data = $request->validate([
            'company_id' => ['nullable', 'string', 'uuid'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.ServicePackageOperations::MAX_LIMIT],
            'cursor' => ['nullable', 'string', 'max:2048'],
        ]);

        return response()->json($this->services->list($actor, $workspace, $data['company_id'] ?? null, (int) ($data['limit'] ?? 25), $data['cursor'] ?? null));
    }

    public function showClientService(Request $request, Workspace $workspace, string $service): JsonResponse
    {
        return response()->json(['data' => $this->services->get($this->reader($request), $workspace, $service)]);
    }

    public function cancellationRequests(Request $request, Workspace $workspace): JsonResponse
    {
        [$actor, $status, $limit, $cursor] = $this->listing($request, CancellationRequestStatus::class);

        return response()->json($this->cancellationReads->list($actor, $workspace, $status, $limit, $cursor));
    }

    public function showCancellationRequest(Request $request, Workspace $workspace, string $cancellation): JsonResponse
    {
        return response()->json(['data' => $this->cancellationReads->get($this->reader($request), $workspace, $cancellation)]);
    }

    public function approveCancellationRequest(Request $request, Workspace $workspace, string $cancellation): JsonResponse
    {
        return $this->decideCancellation($request, $workspace, 'cancellation_requests.approve', $cancellation);
    }

    public function declineCancellationRequest(Request $request, Workspace $workspace, string $cancellation): JsonResponse
    {
        return $this->decideCancellation($request, $workspace, 'cancellation_requests.decline', $cancellation);
    }

    /** The agreement as agreements.get answers it, with its cancellation state. */
    public function scheduleAgreementEnd(Request $request, Workspace $workspace, string $agreement): JsonResponse
    {
        return $this->agreementEnd($request, $workspace, 'agreements.schedule_end', $agreement);
    }

    public function undoAgreementEnd(Request $request, Workspace $workspace, string $agreement): JsonResponse
    {
        return $this->agreementEnd($request, $workspace, 'agreements.undo_scheduled_end', $agreement);
    }

    /** What moving a version's subscribers to the published version would do (MIG-1, MIG-4); changes nothing. */
    public function previewMigration(Request $request, Workspace $workspace, string $package): JsonResponse
    {
        $actor = $this->reader($request);
        $data = $request->validate([
            'from_version_id' => ['required', 'string', 'uuid'],
            'effective_on' => ['nullable', 'string', 'date_format:Y-m-d'],
        ]);

        return response()->json(['data' => $this->planner->preview($actor, $workspace, $this->reads->find($actor, $workspace, $package), $data['from_version_id'], $data['effective_on'] ?? null)]);
    }

    public function scheduleMigration(Request $request, Workspace $workspace, string $package): JsonResponse
    {
        $context = $this->contexts->fromAuthenticatedClient($request);
        $migration = $this->writes->scheduleMigration($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey, $package, $request->all());

        return response()->json(['data' => $this->migrations->get($context->user, $workspace, $migration->public_id)], 201);
    }

    public function migrations(Request $request, Workspace $workspace): JsonResponse
    {
        [$actor, $status, $limit, $cursor] = $this->listing($request, MigrationStatus::class);

        return response()->json($this->migrations->list($actor, $workspace, $status, $limit, $cursor));
    }

    public function showMigration(Request $request, Workspace $workspace, string $migration): JsonResponse
    {
        return response()->json(['data' => $this->migrations->get($this->reader($request), $workspace, $migration)]);
    }

    public function cancelMigration(Request $request, Workspace $workspace, string $migration): JsonResponse
    {
        $context = $this->contexts->fromAuthenticatedClient($request);
        $this->writes->cancelMigration($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey, $migration, $request->all());

        return response()->json(['data' => $this->migrations->get($context->user, $workspace, $migration)]);
    }

    private function decideCancellation(Request $request, Workspace $workspace, string $operation, string $cancellation): JsonResponse
    {
        $context = $this->contexts->fromAuthenticatedClient($request);
        $this->writes->decideCancellation($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey, $operation, $cancellation, $request->all());

        return response()->json(['data' => $this->cancellationReads->get($context->user, $workspace, $cancellation)]);
    }

    private function agreementEnd(Request $request, Workspace $workspace, string $operation, string $agreement): JsonResponse
    {
        $context = $this->contexts->fromAuthenticatedClient($request);
        $id = $this->writes->agreementEnd($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey, $operation, $agreement, $request->all());

        return response()->json(['data' => $this->agreements->get($context->user, $workspace, $id)]);
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
     * @template T of PackageStatus|PackageRequestStatus|EnrollmentStatus|CancellationRequestStatus|MigrationStatus
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
