<?php

namespace App\Support\Navigation;

/**
 * What the chrome may offer this viewer, as three separate answers.
 *
 * Separate rather than one role string because the navbar asks three different
 * questions and a role would let the browser decide which of them a role
 * implies. Each gates a link only; every action behind one authorizes on its
 * own, because a link nobody rendered is not a check.
 */
final class WorkspaceNavigationPermissions
{
    public function __construct(
        public readonly bool $manageWorkspace,
        public readonly bool $createClient,
        public readonly bool $manageCurrentClient,
    ) {}

    /**
     * @return array{manage_workspace: bool, create_client: bool, manage_current_client: bool}
     */
    public function toArray(): array
    {
        return [
            'manage_workspace' => $this->manageWorkspace,
            'create_client' => $this->createClient,
            'manage_current_client' => $this->manageCurrentClient,
        ];
    }
}
