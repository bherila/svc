<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentCapacityLedgerReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Thin REST adapter over the canonical capacity ledger read workflow. */
final class AgentCapacityLedgerController extends Controller
{
    public function show(Request $request, Workspace $workspace, string $agreement, AgentCapacityLedgerReadService $ledger): JsonResponse
    {
        $data = $request->validate(['months' => ['sometimes', 'integer', 'min:1', 'max:60']]);
        $user = $request->user();
        abort_unless($user instanceof User || $user instanceof AgentPrincipal, 401);

        return response()->json(['data' => $ledger->get($user, $workspace, $agreement, (int) ($data['months'] ?? 12))]);
    }
}
