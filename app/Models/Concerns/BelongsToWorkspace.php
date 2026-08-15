<?php

namespace App\Models\Concerns;

trait BelongsToWorkspace
{
    public function workspaceId(): ?int
    {
        $workspaceId = $this->getAttribute('workspace_id');

        return $workspaceId === null ? null : (int) $workspaceId;
    }
}
