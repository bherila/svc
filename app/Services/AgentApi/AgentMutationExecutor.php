<?php

namespace App\Services\AgentApi;

use App\Exceptions\AccessMutationRefused;
use App\Models\AgentMutationAudit;
use App\Models\AgentMutationReceipt;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Access\AccessRefusal;
use App\Support\Concurrency\Locks;
use App\Support\WorkspaceClock;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class AgentMutationExecutor
{
    public function __construct(private readonly WorkspaceClock $clock = new WorkspaceClock) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(): list<string>  $callback
     * @param  (callable(list<string>): void)|null  $replayGuard
     * @param  (callable(list<string>): array<string, mixed>)|null  $responseSnapshot
     * @return list<string>
     */
    public function run(
        User $user,
        Workspace $workspace,
        string $clientId,
        string $operation,
        string $key,
        array $payload,
        callable $callback,
        ?callable $replayGuard = null,
        ?string $additionalAuditOperation = null,
        ?callable $responseSnapshot = null,
    ): array {
        $digest = hash('sha256', json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($user, $workspace, $clientId, $operation, $key, $digest, $callback, $replayGuard, $additionalAuditOperation, $responseSnapshot): array {
                app(LegacyAgentReceiptNamespace::class)->reserve($user, $workspace, $clientId, $operation, $key);
                // Keep the compatibility reservation when replaying an existing receipt.
                // createOrFirst isolates a duplicate insert in a savepoint, not this transaction.
                $receipt = AgentMutationReceipt::query()->where('workspace_id', $workspace->id)->tap(Locks::forUpdate())->createOrFirst([
                    'user_id' => $user->id,
                    'workspace_id' => $workspace->id,
                    'oauth_client_id' => $clientId,
                    'operation' => $operation,
                    'idempotency_key' => $key,
                ], [
                    'request_digest' => $digest,
                    'status' => 'pending',
                    'result_public_ids' => [],
                ]);
                if (! $receipt->wasRecentlyCreated) {
                    abort_unless(hash_equals($receipt->request_digest, $digest), 409, 'The idempotency key was already used with a different request.');
                    abort_unless($receipt->status === 'completed', 409, 'The original mutation is still being processed.');
                    $ids = $this->ids($receipt);
                    if ($replayGuard !== null) {
                        $replayGuard($ids);
                    }
                    $this->audit($user, $workspace, $clientId, $operation, $ids, 'replay');
                    if ($additionalAuditOperation !== null) {
                        $this->audit($user, $workspace, $clientId, $additionalAuditOperation, $ids, 'replay');
                    }

                    return $ids;
                }
                $ids = $callback();
                $receipt->forceFill([
                    'status' => 'completed',
                    'result_public_ids' => $ids,
                    'response_snapshot' => $responseSnapshot === null ? null : $responseSnapshot($ids),
                    'completed_at' => $this->clock->now($workspace),
                ])->save();
                $this->audit($user, $workspace, $clientId, $operation, $ids, 'success');
                if ($additionalAuditOperation !== null) {
                    $this->audit($user, $workspace, $clientId, $additionalAuditOperation, $ids, 'success');
                }

                return $ids;
            });
        } catch (Throwable $exception) {
            $this->auditFailure($user, $workspace, $clientId, $operation, $exception);
            if ($additionalAuditOperation !== null) {
                $this->auditFailure($user, $workspace, $clientId, $additionalAuditOperation, $exception);
            }

            throw $exception;
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map($this->canonicalize(...), $value);
        }

        ksort($value);

        return array_map($this->canonicalize(...), $value);
    }

    /** @return list<string> */
    private function ids(AgentMutationReceipt $receipt): array
    {
        $ids = $receipt->getAttribute('result_public_ids');
        if (! is_array($ids) || ! array_is_list($ids)) {
            throw new \UnexpectedValueException('The stored mutation result is invalid.');
        }
        foreach ($ids as $id) {
            if (! is_string($id)) {
                throw new \UnexpectedValueException('The stored mutation result is invalid.');
            }
        }

        return $ids;
    }

    /** @param list<string> $ids */
    private function audit(User $user, Workspace $workspace, string $clientId, string $operation, array $ids, string $outcome, ?string $errorCategory = null): void
    {
        AgentMutationAudit::query()->create([
            'user_id' => $user->id,
            'oauth_client_id' => $clientId,
            'workspace_id' => $workspace->id,
            'operation' => $operation,
            'affected_public_ids' => $ids,
            'request_id' => (string) Str::uuid(),
            'outcome' => $outcome,
            'error_category' => $errorCategory,
        ]);
    }

    private function auditFailure(User $user, Workspace $workspace, string $clientId, string $operation, Throwable $exception): void
    {
        try {
            $this->audit($user, $workspace, $clientId, $operation, [], 'failed', $this->errorCategory($exception));
        } catch (Throwable) {
            Log::warning('Agent mutation failure audit could not be recorded.', [
                'workspace_id' => $workspace->public_id,
                'operation' => $operation,
            ]);
        }
    }

    private function errorCategory(Throwable $exception): string
    {
        if ($exception instanceof AccessMutationRefused) {
            return match ($exception->reason) {
                AccessRefusal::NotAuthorized => 'forbidden',
                AccessRefusal::PortalRevisionConflict, AccessRefusal::InvitationNotOpen => 'conflict',
                AccessRefusal::OwnerProtected, AccessRefusal::SelfChange, AccessRefusal::AlreadyStaff,
                AccessRefusal::NotStaff, AccessRefusal::UnsupportedRole, AccessRefusal::AlreadyPortalMember,
                AccessRefusal::NotPortalMember, AccessRefusal::ProjectOutsideCompany, AccessRefusal::ProjectsWithCompanyScope,
                AccessRefusal::AccountExists, AccessRefusal::InviterNotBound => 'domain',
            };
        }
        if ($exception instanceof ValidationException) {
            return 'validation';
        }
        if ($exception instanceof AuthorizationException) {
            return 'forbidden';
        }
        if ($exception instanceof ModelNotFoundException) {
            return 'not_found';
        }
        if ($exception instanceof HttpExceptionInterface) {
            return match ($exception->getStatusCode()) {
                403 => 'forbidden',
                404 => 'not_found',
                409 => 'conflict',
                422 => 'validation',
                default => 'http_error',
            };
        }
        if ($exception instanceof DomainException) {
            return 'domain';
        }

        return 'internal';
    }
}
