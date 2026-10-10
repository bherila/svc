<?php

namespace App\Services\AgentApi\Operations;

use Bherila\McpLaravelBridge\Capabilities\AuthenticatedPrincipal;
use Closure;

/**
 * The caller as the operation registry sees it: the scopes its credential
 * holds and, for discovery, whether it manages any workspace.
 *
 * SVC declares no permissions or groups on operations: what a person may do
 * to a record is decided by the domain actions, and the only coarse rule is
 * the workspace-manager one {@see AgentOperationPolicy} applies.
 */
final class AgentOperationPrincipal implements AuthenticatedPrincipal
{
    private ?bool $manages = null;

    /**
     * @param  Closure(string): bool  $scopes
     * @param  Closure(): bool  $managesAnyWorkspace  evaluated at most once, and only when asked
     */
    public function __construct(
        private readonly Closure $scopes,
        private readonly Closure $managesAnyWorkspace,
        private readonly bool $authenticated = true,
    ) {}

    public static function anonymous(): self
    {
        return new self(static fn (string $scope): bool => false, static fn (): bool => false, false);
    }

    public function hasScope(string $scope): bool
    {
        return ($this->scopes)($scope);
    }

    public function can(string $permission): bool
    {
        return false;
    }

    public function allowsGroup(?string $group): bool
    {
        return $group === null;
    }

    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    public function managesWorkspace(): bool
    {
        return $this->manages ??= ($this->managesAnyWorkspace)();
    }
}
