<?php

namespace App\Services\Access;

use App\Models\AccessAuditEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Access\AccessAuditAction;
use App\Support\Access\AccessMutationContext;
use App\Support\WorkspaceClock;

/**
 * Appends access audit events, inside the transaction of the change they
 * describe, so a change and its record commit or roll back together.
 */
final class AccessAuditLog
{
    public function __construct(private readonly WorkspaceClock $clock = new WorkspaceClock) {}

    /**
     * @param  array<string, mixed>|null  $before  ids, roles and scopes only
     * @param  array<string, mixed>|null  $after  ids, roles and scopes only
     */
    public function record(
        AccessMutationContext $context,
        Workspace $workspace,
        AccessAuditAction $action,
        User $subject,
        ?array $before,
        ?array $after,
        ?string $companyPublicId = null,
    ): AccessAuditEvent {
        return AccessAuditEvent::query()->create([
            'workspace_id' => $workspace->id,
            'actor_type' => $context->isSystem() ? AccessAuditEvent::ACTOR_SYSTEM : AccessAuditEvent::ACTOR_USER,
            'actor_user_id' => $context->actorId(),
            'subject_user_id' => $subject->id,
            'action' => $action->value,
            'client_company_public_id' => $companyPublicId,
            'before_state' => $before,
            'after_state' => $after,
            'operation_id' => $context->operationId,
            'request_id' => $context->requestId,
            'created_at' => $this->clock->now($workspace),
        ]);
    }
}
