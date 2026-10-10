<?php

namespace App\Services\Mcp\Context;

use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * Authenticated context facts for capability discovery: the scopes held, and
 * whether the caller manages any workspace - the fact the operation policy's
 * workspace-manager rule needs. Object and workspace policy enforcement
 * remains in the scoped application read/action invoked by each capability.
 */
final class McpAuthorizer
{
    public function __construct(private readonly AgentAccess $access) {}

    /** @param list<string> $requiredScopes */
    public function allowsScopes(McpRequestContext $context, array $requiredScopes): bool
    {
        foreach ($requiredScopes as $scope) {
            if (! $context->principal->hasScope($scope)) {
                return false;
            }
        }

        return true;
    }

    public function hasManagedWorkspace(McpRequestContext $context): bool
    {
        $subject = $context->principal->subject;

        return Workspace::query()
            ->whereHas('memberships', fn (Builder $memberships): Builder => $memberships
                ->where('user_id', $subject->id)
                ->whereIn('role', ['owner', 'admin']))
            ->lazyById()
            ->contains(fn (Workspace $workspace): bool => $this->access->isWorkspaceManager($subject, $workspace));
    }
}
