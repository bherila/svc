<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\ClientExpense;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentExpenseReadService;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Services\AgentApi\AgentMutationExecutor;
use App\Services\AgentApi\AgentReadService;
use App\Services\Approval\DisputeSettlements;
use App\Services\Authorization\ProjectAccess;
use App\Support\Approval\DisputeSettlement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Settle a client's dispute on one hour or one expense (docs/ux-overhaul.md
 * 7b″): answer it, putting the row back before the client, or write it off.
 * Each is idempotent by its Idempotency-Key, version-checked, and needs
 * `confirm: true`, because the reply is sent to the client as written.
 */
final class AgentDisputeController extends Controller
{
    public function __construct(
        private readonly AgentMutationContextFactory $contexts,
        private readonly AgentMutationExecutor $mutations,
        private readonly DisputeSettlements $settlements,
    ) {}

    public function settleTime(Request $request, Workspace $workspace, string $entry, AgentReadService $reads): JsonResponse
    {
        $context = $this->contexts->from($request);
        $reader = $request->user();
        abort_unless($reader instanceof User || $reader instanceof AgentPrincipal, 401);
        $ids = $this->mutations->run(
            $context->user,
            $workspace,
            $context->oauthClientId,
            'time_entries.settle_dispute',
            $context->idempotencyKey,
            ['entry_id' => $entry, 'body' => $request->all()],
            function () use ($request, $workspace, $entry, $context): array {
                $data = $this->validated($request);

                return [$this->settlements->settleTime($workspace, $entry, $context->user, DisputeSettlement::from($data['outcome']), $data['reply'], $data['expected_version'])->public_id];
            },
            // A replay answers only someone who can still read the entry, by
            // the same row-level visibility `time_entries.list` applies.
            function (array $ids) use ($reads, $reader, $workspace): void {
                $reads->timeEntriesByIds($reader, $workspace, $ids);
            },
        );

        return response()->json(['data' => $reads->timeEntriesByIds($reader, $workspace, $ids)[0]]);
    }

    public function settleExpense(Request $request, Workspace $workspace, string $expense, ProjectAccess $access, AgentExpenseReadService $reads): JsonResponse
    {
        $context = $this->contexts->from($request);
        $ids = $this->mutations->run(
            $context->user,
            $workspace,
            $context->oauthClientId,
            'expenses.settle_dispute',
            $context->idempotencyKey,
            ['expense_id' => $expense, 'body' => $request->all()],
            function () use ($request, $workspace, $expense, $context): array {
                $data = $this->validated($request);

                return [$this->settlements->settleExpense($workspace, $expense, $context->user, DisputeSettlement::from($data['outcome']), $data['reply'], $data['expected_version'])->public_id];
            },
            function (array $ids) use ($workspace, $access, $context): void {
                abort_unless($access->isWorkspaceManager($context->user, $workspace), 403);
                abort_unless(ClientExpense::query()->where('workspace_id', $workspace->id)->whereIn('public_id', $ids)->count() === count($ids), 404);
            },
        );

        return response()->json(['data' => $reads->results($context->user, $workspace, $ids)[0]]);
    }

    /** @return array{expected_version: string, outcome: string, reply: string, confirm: bool} */
    private function validated(Request $request): array
    {
        /** @var array{expected_version: string, outcome: string, reply: string, confirm: bool} */
        return $request->validate([
            'expected_version' => ['required', 'string', 'size:64'],
            'outcome' => ['required', 'string', Rule::in(DisputeSettlement::values())],
            'reply' => ['required', 'string', 'max:'.DisputeSettlements::REPLY_MAX],
            'confirm' => ['required', 'accepted'],
        ]);
    }
}
