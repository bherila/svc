<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The inner cutover for agent-initiated invoice writes.
 *
 * Stacked *after* {@see EnsureAgentWritesEnabled} rather than replacing it, so
 * the effective condition is both flags: `AGENT_API_WRITES_ENABLED` keeps
 * meaning what it means today for tasks and time approval, and admitting an
 * agent to the invoice surface is a second, deliberate act.
 *
 * The boundary follows blast radius rather than implementation convenience.
 * Creating a task is recoverable bookkeeping; `invoices.send` puts a document
 * in front of a paying client and `invoices.issue` allocates approved time
 * irreversibly. One flag for both meant an operator who wanted agent-assisted
 * time approval had to accept agent-initiated invoice delivery in the same
 * move (#242).
 *
 * 404 rather than 403, matching the two flags either side of it: a disabled
 * surface is not a permission the caller might be granted, and saying so
 * discloses the deployment's configuration to an unauthorized client.
 */
final class EnsureAgentInvoiceWritesEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('agent_api.invoice_writes_enabled'), 404);

        return $next($request);
    }
}
