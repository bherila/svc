<?php

namespace App\Services\Mcp\Context;

use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** Resolves a workspace selector only inside the authenticated principal's reach. */
final class McpAccountContextResolver
{
    public function __construct(private readonly AgentAccess $access) {}

    /**
     * The selector is intentionally optional while existing MCP clients still
     * send a workspace ID to each tool. It is never an authority grant.
     *
     * @throws ModelNotFoundException
     */
    public function resolve(McpRequestContext $context, string $workspaceId): McpRequestContext
    {
        $subject = $context->principal->subject;
        $workspace = Workspace::query()
            ->where('public_id', $workspaceId)
            ->where(function (Builder $workspaces) use ($subject): void {
                $workspaces
                    ->whereHas('memberships', fn (Builder $memberships): Builder => $memberships->where('user_id', $subject->id))
                    ->orWhereHas('clientCompanies.portalUsers', fn (Builder $users): Builder => $users->whereKey($subject->id));
            })
            ->first();

        if (! $workspace instanceof Workspace || ! $this->access->canViewWorkspace($subject, $workspace)) {
            throw (new ModelNotFoundException)->setModel(Workspace::class);
        }

        return $context->forWorkspace($workspace);
    }
}
