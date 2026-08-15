<?php

namespace App\Services;

use App\Contracts\WorkspaceOwned;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class WorkspaceAuthorization
{
    public function isOwnedBy(Workspace $workspace, WorkspaceOwned $resource): bool
    {
        return $resource->workspaceId() === $workspace->id;
    }

    public function assertOwnedBy(Workspace $workspace, Model&WorkspaceOwned $resource): void
    {
        if (! $this->isOwnedBy($workspace, $resource)) {
            throw (new ModelNotFoundException)->setModel($resource::class);
        }
    }
}
