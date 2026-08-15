<?php

namespace App\Contracts;

interface WorkspaceOwned
{
    public function workspaceId(): ?int;
}
