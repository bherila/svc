<?php

namespace App\Services\Mcp\Context;

/**
 * Central capability eligibility check over authenticated context facts.
 *
 * This deliberately establishes discovery eligibility only. Object and
 * workspace policy enforcement remains in the scoped application read/action
 * invoked by each capability.
 */
final class McpAuthorizer
{
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
}
