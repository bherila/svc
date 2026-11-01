<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentBillingAuditReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use stdClass;

/**
 * The aggregate billing audits over REST, and over MCP through the same
 * routes (#459). Each is a thin adapter over {@see AgentBillingAuditReadService},
 * which checks the caller manages the workspace and applies the preflight's
 * period cap and per-workspace budget, so every caller meets the same limits.
 */
final class AgentBillingAuditController extends Controller
{
    public function __construct(private readonly AgentBillingAuditReadService $audits) {}

    public function unplaceableInvoices(Request $request, Workspace $workspace): JsonResponse
    {
        return $this->data($this->audits->unplaceableInvoices($this->principal($request), $workspace));
    }

    public function undatedCollectibleInvoices(Request $request, Workspace $workspace): JsonResponse
    {
        $data = $this->audits->undatedCollectibleInvoices($this->principal($request), $workspace);
        // Balances by currency are a map: none is `{}`, not `[]`.
        foreach (['undated_balances', 'would_become_overdue_balances'] as $map) {
            if ($data[$map] === []) {
                $data[$map] = new stdClass;
            }
        }

        return $this->data($data);
    }

    public function missingBilledOverage(Request $request, Workspace $workspace): JsonResponse
    {
        return $this->data($this->audits->missingBilledOverage($this->principal($request), $workspace));
    }

    public function scheduleGenerationPreflight(Request $request, Workspace $workspace): JsonResponse
    {
        return $this->data($this->audits->scheduleGenerationPreflight($this->principal($request), $workspace));
    }

    public function unlinkedProposalAgreements(Request $request, Workspace $workspace): JsonResponse
    {
        return $this->data($this->audits->unlinkedProposalAgreements($this->principal($request), $workspace));
    }

    /** @param array<string, mixed> $data */
    private function data(array $data): JsonResponse
    {
        return response()->json(['data' => $data]);
    }

    private function principal(Request $request): User|AgentPrincipal
    {
        $principal = $request->user();
        abort_unless($principal instanceof User || $principal instanceof AgentPrincipal, 401);

        return $principal;
    }
}
