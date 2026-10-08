<?php

namespace App\Http\Middleware;

use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAgentWorkspaceVisible
{
    public function __construct(private readonly AgentAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User || $actor instanceof AgentPrincipal, 401);
        $workspace = $request->route('workspace');
        if (! $workspace instanceof Workspace || ! $this->access->canViewWorkspace($actor, $workspace)) {
            throw (new ModelNotFoundException)->setModel(Workspace::class);
        }

        return $next($request);
    }
}
