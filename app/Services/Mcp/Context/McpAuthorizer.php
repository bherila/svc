<?php

namespace App\Services\Mcp\Context;

use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Mcp\Registry\McpCapabilityDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * Central capability eligibility check over authenticated context facts.
 *
 * This deliberately establishes discovery eligibility only. Object and
 * workspace policy enforcement remains in the scoped application read/action
 * invoked by each capability.
 */
final class McpAuthorizer
{
    public function __construct(private readonly AgentAccess $access) {}

    public function allowsDiscovery(McpRequestContext $context, McpCapabilityDefinition $definition): bool
    {
        if (! $this->allowsScopes($context, $definition->requiredScopes)) {
            return false;
        }

        // A manager-only capability can never succeed for a portal user or a
        // non-manager member. Resolve that fact through the same AgentAccess
        // policy used by the backing read services, before advertising it.
        // Object-specific policies remain enforced by their scoped reads.
        return $definition->policyAbility !== 'AgentAccess::isWorkspaceManager'
            || $this->hasManagedWorkspace($context);
    }

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

    private function hasManagedWorkspace(McpRequestContext $context): bool
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
