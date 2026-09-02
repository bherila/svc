<?php

namespace App\Services\Mcp\Context;

use Illuminate\Http\Request;

/**
 * Resolves authenticated principal facts for an MCP request.
 *
 * The local Passport implementation remains authoritative. This seam permits
 * a future shared resource-server adapter without changing MCP handlers.
 */
interface McpPrincipalResolverInterface
{
    public function resolve(Request $request): McpPrincipal;
}
