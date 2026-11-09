<?php

namespace App\Services\Mcp;

use App\Services\AgentApi\Operations\ServicePackageOperations;
use Bherila\McpLaravelBridge\Mcp\RequestArguments;
use Mcp\Capability\Attribute\Schema;
use Mcp\Server\RequestContext;

/**
 * The service package, sign-up request, enrollment, client service and
 * cancellation tools. Each calls its REST operation, so the manager
 * rule, workspace scoping, validation, versions, receipts and cutovers are the
 * route's and cannot differ between the two transports (API-1). A write's
 * arguments are passed on exactly as sent: the fields its request schema
 * names, and nothing the caller left out.
 */
final class AgentMcpPackageTools
{
    public function __construct(private readonly AgentMcpRestCall $rest, private readonly RequestArguments $arguments) {}

    /** @return array<string, mixed> */
    public function list(#[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(enum: ['draft', 'published', 'retired'])] ?string $status = null,
        #[Schema(minimum: 1, maximum: ServicePackageOperations::MAX_LIMIT)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null): array
    {
        return $this->rest->call('packages.list', ['workspace' => $workspace_id],
            query: array_filter(compact('status', 'limit', 'cursor'), static fn (mixed $value): bool => $value !== null));
    }

    /** @return array<string, mixed> */
    public function get(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $package_id): array
    {
        return $this->rest->call('packages.get', ['workspace' => $workspace_id, 'package' => $package_id]);
    }

    /** @return array<string, mixed> */
    public function create(#[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        RequestContext $request): array
    {
        return $this->rest->call('packages.create', ['workspace' => $workspace_id], $this->body($request, 'packages.create'), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function update(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $package_id,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        RequestContext $request): array
    {
        return $this->rest->call('packages.update', ['workspace' => $workspace_id, 'package' => $package_id], $this->body($request, 'packages.update'), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function publish(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $package_id,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        RequestContext $request): array
    {
        return $this->rest->call('packages.publish', ['workspace' => $workspace_id, 'package' => $package_id], $this->body($request, 'packages.publish'), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function retire(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $package_id,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        RequestContext $request): array
    {
        return $this->rest->call('packages.retire', ['workspace' => $workspace_id, 'package' => $package_id], $this->body($request, 'packages.retire'), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function listRequests(#[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(enum: ['new', 'approved', 'declined'])] ?string $status = null,
        #[Schema(minimum: 1, maximum: ServicePackageOperations::MAX_LIMIT)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null): array
    {
        return $this->rest->call('package_requests.list', ['workspace' => $workspace_id],
            query: array_filter(compact('status', 'limit', 'cursor'), static fn (mixed $value): bool => $value !== null));
    }

    /** @return array<string, mixed> */
    public function getRequest(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $request_id): array
    {
        return $this->rest->call('package_requests.get', ['workspace' => $workspace_id, 'request' => $request_id]);
    }

    /** @return array<string, mixed> */
    public function approveRequest(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $request_id,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        RequestContext $request): array
    {
        return $this->decide('package_requests.approve', $workspace_id, $request_id, $idempotency_key, $request);
    }

    /** @return array<string, mixed> */
    public function declineRequest(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $request_id,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        RequestContext $request): array
    {
        return $this->decide('package_requests.decline', $workspace_id, $request_id, $idempotency_key, $request);
    }

    /** @return array<string, mixed> */
    public function eraseRequest(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $request_id,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        RequestContext $request): array
    {
        return $this->decide('package_requests.erase', $workspace_id, $request_id, $idempotency_key, $request);
    }

    /** @return array<string, mixed> */
    public function listEnrollments(#[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(enum: ['offered', 'awaiting_payment', 'awaiting_review', 'active', 'lapsed', 'withdrawn'])] ?string $status = null,
        #[Schema(minimum: 1, maximum: ServicePackageOperations::MAX_LIMIT)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null): array
    {
        return $this->rest->call('enrollments.list', ['workspace' => $workspace_id],
            query: array_filter(compact('status', 'limit', 'cursor'), static fn (mixed $value): bool => $value !== null));
    }

    /** @return array<string, mixed> */
    public function getEnrollment(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $enrollment_id): array
    {
        return $this->rest->call('enrollments.get', ['workspace' => $workspace_id, 'enrollment' => $enrollment_id]);
    }

    /** @return array<string, mixed> */
    public function activateEnrollment(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $enrollment_id,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        RequestContext $request): array
    {
        return $this->rest->call('enrollments.activate', ['workspace' => $workspace_id, 'enrollment' => $enrollment_id], $this->body($request, 'enrollments.activate'), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function listClientServices(#[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] ?string $company_id = null,
        #[Schema(minimum: 1, maximum: ServicePackageOperations::MAX_LIMIT)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null): array
    {
        return $this->rest->call('client_services.list', ['workspace' => $workspace_id],
            query: array_filter(compact('company_id', 'limit', 'cursor'), static fn (mixed $value): bool => $value !== null));
    }

    /** @return array<string, mixed> */
    public function getClientService(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $service_id): array
    {
        return $this->rest->call('client_services.get', ['workspace' => $workspace_id, 'service' => $service_id]);
    }

    /** @return array<string, mixed> */
    public function listCancellationRequests(#[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(enum: ['pending', 'approved', 'declined', 'withdrawn'])] ?string $status = null,
        #[Schema(minimum: 1, maximum: ServicePackageOperations::MAX_LIMIT)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null): array
    {
        return $this->rest->call('cancellation_requests.list', ['workspace' => $workspace_id],
            query: array_filter(compact('status', 'limit', 'cursor'), static fn (mixed $value): bool => $value !== null));
    }

    /** @return array<string, mixed> */
    public function getCancellationRequest(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $cancellation_request_id): array
    {
        return $this->rest->call('cancellation_requests.get', ['workspace' => $workspace_id, 'cancellation' => $cancellation_request_id]);
    }

    /** @return array<string, mixed> */
    public function approveCancellationRequest(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $cancellation_request_id,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        RequestContext $request): array
    {
        return $this->rest->call('cancellation_requests.approve', ['workspace' => $workspace_id, 'cancellation' => $cancellation_request_id], $this->body($request, 'cancellation_requests.approve'), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function declineCancellationRequest(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $cancellation_request_id,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        RequestContext $request): array
    {
        return $this->rest->call('cancellation_requests.decline', ['workspace' => $workspace_id, 'cancellation' => $cancellation_request_id], $this->body($request, 'cancellation_requests.decline'), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function scheduleAgreementEnd(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $agreement_id,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        RequestContext $request): array
    {
        return $this->rest->call('agreements.schedule_end', ['workspace' => $workspace_id, 'agreement' => $agreement_id], $this->body($request, 'agreements.schedule_end'), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function undoAgreementEnd(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $agreement_id,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        RequestContext $request): array
    {
        return $this->rest->call('agreements.undo_scheduled_end', ['workspace' => $workspace_id, 'agreement' => $agreement_id], $this->body($request, 'agreements.undo_scheduled_end'), $idempotency_key);
    }

    /** @return array<string, mixed> */
    private function decide(string $operation, string $workspace_id, string $request_id, string $idempotency_key, RequestContext $request): array
    {
        return $this->rest->call($operation, ['workspace' => $workspace_id, 'request' => $request_id], $this->body($request, $operation), $idempotency_key);
    }

    /** @return array<string, mixed> */
    private function body(RequestContext $request, string $operation): array
    {
        $body = [];
        foreach (ServicePackageOperations::bodyFields($operation) as $field) {
            if ($this->arguments->has($request, $field)) {
                $body[$field] = $this->arguments->value($request, $field, null);
            }
        }

        return $body;
    }
}
