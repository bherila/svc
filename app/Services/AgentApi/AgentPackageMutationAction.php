<?php

namespace App\Services\AgentApi;

use App\Models\ServicePackage;
use App\Models\ServicePackageEnrollment;
use App\Models\ServicePackageRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\Operations\ServicePackageOperations;
use App\Services\Authorization\AgentAccess;
use App\Services\Packages\PackageEnrollmentReadService;
use App\Services\Packages\PackageEnrollmentWorkflow;
use App\Services\Packages\PackageRequestReadService;
use App\Services\Packages\PackageRequestWorkflow;
use App\Services\Packages\ServicePackageReadService;
use App\Services\Packages\ServicePackageWorkflow;
use App\Support\AgentApi\ExplicitConfirmation;
use Closure;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * The agent API's way into {@see ServicePackageWorkflow},
 * {@see PackageRequestWorkflow} and {@see PackageEnrollmentWorkflow}: an idempotency receipt, the closed body its
 * contract publishes, `expected_version` wherever that body names one, and
 * `confirm: true` wherever it names that (API-2). Everything about the package
 * or the request itself - authorization, validation, locking, versions and
 * refusals - is the workflow's, exactly as for the website.
 */
final class AgentPackageMutationAction
{
    public function __construct(
        private readonly AgentMutationExecutor $mutations,
        private readonly ServicePackageWorkflow $workflow,
        private readonly ServicePackageReadService $reads,
        private readonly PackageRequestWorkflow $requestWorkflow,
        private readonly PackageRequestReadService $requestReads,
        private readonly AgentAccess $access,
        private readonly PackageEnrollmentWorkflow $enrollmentWorkflow,
        private readonly PackageEnrollmentReadService $enrollmentReads,
    ) {}

    /** @param array<string, mixed> $payload */
    public function run(User $actor, Workspace $workspace, string $clientId, string $key, string $operation, ?string $packageId, array $payload): ServicePackage
    {
        $id = $this->execute($actor, $workspace, $clientId, $key, $operation, ['package_id' => $packageId, 'body' => $payload],
            function (array $terms, ?string $version) use ($actor, $workspace, $operation, $packageId): string {
                $package = $packageId === null ? null : $this->reads->find($actor, $workspace, $packageId);

                return (match ($operation) {
                    'packages.create' => $this->workflow->create($actor, $workspace, $terms),
                    'packages.update' => $this->workflow->update($actor, $workspace, $package ?? abort(404), $terms, $version),
                    'packages.publish' => $this->workflow->publish($actor, $workspace, $package ?? abort(404), $version),
                    'packages.retire' => $this->workflow->retire($actor, $workspace, $package ?? abort(404), $version),
                    default => throw new InvalidArgumentException("Unsupported package mutation [{$operation}]."),
                })->public_id;
            },
            fn (string $id): ServicePackage => $this->reads->find($actor, $workspace, $id));

        return $this->reads->find($actor, $workspace, $id);
    }

    /**
     * Approve, decline or erase a sign-up request.
     *
     * @param  array<string, mixed>  $payload
     */
    public function decide(User $actor, Workspace $workspace, string $clientId, string $key, string $operation, string $requestId, array $payload): ServicePackageRequest
    {
        $id = $this->execute($actor, $workspace, $clientId, $key, $operation, ['request_id' => $requestId, 'body' => $payload],
            function (array $fields, ?string $version) use ($actor, $workspace, $operation, $requestId): string {
                $request = $this->requestReads->find($actor, $workspace, $requestId);
                match ($operation) {
                    'package_requests.approve' => $this->requestWorkflow->approve($actor, $workspace, $request, self::text($fields['client_id'] ?? null), $version, self::text($fields['company_name'] ?? null)),
                    'package_requests.decline' => $this->requestWorkflow->decline($actor, $workspace, $request, self::text($fields['reason'] ?? null), $version),
                    'package_requests.erase' => $this->requestWorkflow->erase($actor, $workspace, $request, $version),
                    default => throw new InvalidArgumentException("Unsupported package request decision [{$operation}]."),
                };

                return $request->public_id;
            },
            fn (string $id): ServicePackageRequest => $this->requestReads->find($actor, $workspace, $id));

        return $this->requestReads->find($actor, $workspace, $id);
    }

    /**
     * Activate an enrollment that waits for review (`enrollments.activate`).
     *
     * @param  array<string, mixed>  $payload
     */
    public function activate(User $actor, Workspace $workspace, string $clientId, string $key, string $enrollmentId, array $payload): ServicePackageEnrollment
    {
        $id = $this->execute($actor, $workspace, $clientId, $key, 'enrollments.activate', ['enrollment_id' => $enrollmentId, 'body' => $payload],
            function (array $fields, ?string $version) use ($actor, $workspace, $enrollmentId): string {
                return $this->enrollmentWorkflow->activate($actor, $workspace, $this->enrollmentReads->find($actor, $workspace, $enrollmentId), $version)->public_id;
            },
            fn (string $id): ServicePackageEnrollment => $this->enrollmentReads->find($actor, $workspace, $id));

        return $this->enrollmentReads->find($actor, $workspace, $id);
    }

    /**
     * One receipted write: the manager rule, then the body its contract
     * publishes, then the workflow call.
     *
     * @param  array{body: array<string, mixed>}&array<string, mixed>  $request  what the receipt fingerprints
     * @param  Closure(array<string, mixed>, ?string): string  $apply  the write, given the body's own fields and the expected version
     * @param  Closure(string): mixed  $stillVisible  refuses a replay to someone who may no longer manage the record
     */
    private function execute(User $actor, Workspace $workspace, string $clientId, string $key, string $operation, array $request, Closure $apply, Closure $stillVisible): string
    {
        Validator::make(['key' => $key], ['key' => ['required', 'string', 'max:255']])->validate();
        $payload = $request['body'];
        $ids = $this->mutations->run($actor, $workspace, $clientId, $operation, $key, $request, function () use ($actor, $workspace, $operation, $payload, $apply): array {
            // A caller who may not manage the workspace learns nothing about
            // which packages or requests exist, before anything about the body (API-5).
            abort_unless($this->access->isWorkspaceManager($actor, $workspace), 404);
            $fields = ServicePackageOperations::bodyFields($operation);
            Validator::make(['body' => $payload], ['body' => ['present', 'array:'.implode(',', $fields)]])->validate();
            $version = in_array('expected_version', $fields, true)
                ? Validator::make($payload, ['expected_version' => ['required', 'string', 'size:64']])->validate()['expected_version']
                : null;
            if (in_array('confirm', $fields, true)) {
                Validator::make($payload, ['confirm' => ['required', new ExplicitConfirmation]])->validate();
            }
            Validator::make($payload, array_intersect_key(ServicePackageOperations::DECISION_RULES, array_flip($fields)))->validate();

            return [$apply(array_diff_key($payload, array_flip(['expected_version', 'confirm'])), $version)];
        }, function (array $ids) use ($stillVisible): void {
            // A replay is answered only to someone who may still manage it.
            $stillVisible((string) ($ids[0] ?? ''));
        });

        return (string) ($ids[0] ?? '');
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
