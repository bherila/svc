<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\ClientExpense;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Approval\PortalApprovalQueue;
use App\Queries\Submission\ApprovedTimesheet;
use App\Queries\Submission\SubmissionsOverview;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Services\AgentApi\AgentMutationExecutor;
use App\Services\Authorization\AgentTokenScopes;
use App\Services\Authorization\ProjectAccess;
use App\Services\Submission\SubmissionService;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentWriteCutover;
use App\Support\Approval\ApprovalPeriod;
use App\Support\Submission\SubmissionState;
use App\Support\WorkspaceClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The submission REST operations: a month's own hours and expenses with where
 * each stands, submitting and withdrawing them, and the approved timesheet
 * (docs/ux-overhaul.md sections 7b and 7e).
 *
 * Every operation acts for the signed-in person on their own rows, inside one
 * workspace they belong to. Submitting is not approving: nothing here writes
 * an approval, a status or a rate.
 */
final class AgentSubmissionController extends Controller
{
    public function __construct(
        private readonly SubmissionService $submissions,
        private readonly SubmissionsOverview $overview,
        private readonly ApprovedTimesheet $timesheet,
        private readonly PortalApprovalQueue $queue,
        private readonly ProjectAccess $access,
        private readonly AgentTokenScopes $scopes,
        private readonly AgentMutationContextFactory $contexts,
        private readonly AgentMutationExecutor $mutations,
        private readonly WorkspaceClock $clock,
    ) {}

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $actor = $this->staff($request, $workspace);
        $input = $this->readInput($request, $workspace);
        $data = $this->overview->for($workspace, $actor, $input['period'], $input['project_id']);
        // Expenses are read only by a token that may read them.
        if (! $this->scopes->allows($request, AgentApiScopes::EXPENSES_READ)) {
            $data['expenses'] = [];
        }

        return response()->json(['data' => $data]);
    }

    public function approved(Request $request, Workspace $workspace): JsonResponse
    {
        $actor = $this->staff($request, $workspace);
        $input = $this->readInput($request, $workspace);

        return response()->json(['data' => $this->timesheet->for($workspace, $actor, $input['period'], $input['project_id'])]);
    }

    public function submit(Request $request, Workspace $workspace): JsonResponse
    {
        return $this->change($request, $workspace, 'submissions.submit',
            fn (User $actor, array $input, bool $expenses): array => $this->submissions->submit($workspace, $actor, $input['period'], $input['project_id'], $input['time_entry_ids'], $input['expense_ids'], $expenses));
    }

    public function withdraw(Request $request, Workspace $workspace): JsonResponse
    {
        return $this->change($request, $workspace, 'submissions.withdraw',
            fn (User $actor, array $input, bool $expenses): array => $this->submissions->withdraw($workspace, $actor, $input['period'], $input['project_id'], $input['time_entry_ids'], $input['expense_ids'], $expenses));
    }

    /**
     * @param  \Closure(User, array{period: ?string, project_id: ?string, time_entry_ids: list<string>, expense_ids: list<string>}, bool): list<array{kind: 'time'|'expense', id: string}>  $write
     */
    private function change(Request $request, Workspace $workspace, string $operation, \Closure $write): JsonResponse
    {
        $mutation = $this->contexts->from($request);
        $this->assertStaff($mutation->user, $workspace);
        $input = $request->validate([
            'period' => ['sometimes', 'nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'project_id' => ['sometimes', 'nullable', 'uuid'],
            'time_entry_ids' => ['sometimes', 'array', 'max:'.SubmissionService::LIMIT],
            'time_entry_ids.*' => ['uuid', 'distinct'],
            'expense_ids' => ['sometimes', 'array', 'max:'.SubmissionService::LIMIT],
            'expense_ids.*' => ['uuid', 'distinct'],
        ]);
        $facts = [
            'period' => $input['period'] ?? null,
            'project_id' => $input['project_id'] ?? null,
            'time_entry_ids' => array_values($input['time_entry_ids'] ?? []),
            'expense_ids' => array_values($input['expense_ids'] ?? []),
        ];

        // Expenses are an expense write: named without the scope and the
        // cutover, they are refused; in a month they are simply left out.
        $mayTouchExpenses = $this->scopes->allows($request, AgentApiScopes::EXPENSES_WRITE) && AgentWriteCutover::expenses();
        abort_if($facts['expense_ids'] !== [] && ! $mayTouchExpenses, 403, 'Submitting expenses needs the expenses:write scope and expense writes switched on.');

        $ids = $this->mutations->run($mutation->user, $workspace, $mutation->oauthClientId, $operation, $mutation->idempotencyKey,
            $facts + ['expenses_included' => $mayTouchExpenses],
            fn (): array => array_map(
                static fn (array $handle): string => $handle['id'],
                $write($mutation->user, $facts, $mayTouchExpenses),
            ),
            // A replay answers with the rows as they stand, so it checks that the
            // caller still belongs to the workspace they were written in.
            fn (array $ids) => $this->assertStaff($mutation->user, $workspace));

        return response()->json(['data' => ['items' => $this->itemsOf($workspace, $ids)]]);
    }

    /**
     * The rows a receipt names, as they stand now.
     *
     * @param  list<string>  $ids
     * @return list<array{kind: 'time'|'expense', id: string, state: string}>
     */
    private function itemsOf(Workspace $workspace, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $items = [];
        $time = ClientTimeEntry::query()->where('workspace_id', $workspace->id)->whereIn('public_id', $ids)
            ->with(['invoiceLines:id,client_invoice_id', 'invoiceLines.invoice:id,workspace_id,status'])->orderBy('id')->get();
        foreach ($time as $entry) {
            $items[] = ['kind' => 'time', 'id' => $entry->public_id, 'state' => SubmissionState::of($entry->status, $entry->submitted_at !== null, $this->queue->verdictOfTime($entry))->value];
        }
        $expenses = ClientExpense::query()->where('workspace_id', $workspace->id)->whereIn('public_id', $ids)
            ->with(['invoiceLine:id,client_invoice_id', 'invoiceLine.invoice:id,workspace_id,status'])->orderBy('id')->get();
        foreach ($expenses as $expense) {
            $items[] = ['kind' => 'expense', 'id' => $expense->public_id, 'state' => SubmissionState::of($expense->status, $expense->submitted_at !== null, $this->queue->verdictOfExpense($expense))->value];
        }

        return $items;
    }

    /** @return array{period: ApprovalPeriod, project_id: ?string} */
    private function readInput(Request $request, Workspace $workspace): array
    {
        $input = $request->validate([
            'period' => ['sometimes', 'nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'project_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
        $period = ApprovalPeriod::fromKey((string) ($input['period'] ?? ''))
            ?? ApprovalPeriod::containing($this->clock->today($workspace)->toDateString());

        return ['period' => $period, 'project_id' => $input['project_id'] ?? null];
    }

    private function staff(Request $request, Workspace $workspace): User
    {
        $principal = $request->user();
        abort_unless($principal instanceof User || $principal instanceof AgentPrincipal, 401);
        $user = User::query()->findOrFail($principal->id);
        $this->assertStaff($user, $workspace);

        return $user;
    }

    private function assertStaff(User $user, Workspace $workspace): void
    {
        abort_if($this->access->workspaceRole($user, $workspace) === null, 404);
    }
}
