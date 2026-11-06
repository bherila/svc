<?php

namespace App\Queries\Submission;

use App\Models\ClientExpense;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Approval\PortalApprovalQueue;
use App\Services\Authorization\ProjectAccess;
use App\Support\Approval\ApprovalPeriod;
use App\Support\Submission\SubmissionRules;
use App\Support\Submission\SubmissionState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * "Submit for review": one person's own hours and expenses for one calendar
 * month, each with where it stands on its way to being approved
 * (docs/ux-overhaul.md sections 7b and 7e).
 *
 * It is the author's page and nothing else's: every read is keyed by the
 * workspace and by the viewer as author, in projects they can see, so there is
 * no other person's row and no other tenant's to leak. A row is read as a
 * date, whole minutes, the words the author wrote and a state - never a rate,
 * an amount of the provider's, an invoice or a reviewer's identity.
 *
 * Two bounded reads, plus the invoices behind the rows in one more each, so the
 * cost is the same however many rows the month holds.
 */
final class SubmissionsOverview
{
    public const int LIMIT = 500;

    public function __construct(
        private readonly ProjectAccess $access,
        private readonly PortalApprovalQueue $queue,
    ) {}

    /**
     * @return array{period: string, period_label: string, previous_period: string, next_period: string, time: list<array<string, mixed>>, expenses: list<array<string, mixed>>, totals: array<string, int>, truncated: bool}
     */
    public function for(Workspace $workspace, User $viewer, ApprovalPeriod $period, ?string $projectPublicId = null): array
    {
        $viewable = $this->access->viewableProjectIds($viewer, $workspace);
        $project = $projectPublicId === null ? null : ClientProject::query()->where('workspace_id', $workspace->id)->where('public_id', $projectPublicId)->first();
        abort_if($projectPublicId !== null && $project === null, 404);

        $time = ClientTimeEntry::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $viewer->id)
            ->whereBetween('worked_on', [$period->start(), $period->end()])
            ->tap(fn (Builder $query) => $this->scoped($query, $viewable, $project))
            ->with(['project:id,name', 'invoiceLines:id,client_invoice_id', 'invoiceLines.invoice:id,workspace_id,status'])
            ->orderBy('worked_on')->orderBy('id')
            ->limit(self::LIMIT + 1)
            ->get();
        $expenses = ClientExpense::query()
            ->where('workspace_id', $workspace->id)
            ->where('created_by_user_id', $viewer->id)
            ->whereBetween('spent_on', [$period->start(), $period->end()])
            ->tap(fn (Builder $query) => $this->scoped($query, $viewable, $project))
            ->with(['project:id,name', 'invoiceLine:id,client_invoice_id', 'invoiceLine.invoice:id,workspace_id,status'])
            ->orderBy('spent_on')->orderBy('id')
            ->limit(self::LIMIT + 1)
            ->get();
        $truncated = $time->count() > self::LIMIT || $expenses->count() > self::LIMIT;

        $timeRows = $time->take(self::LIMIT)->map(fn (ClientTimeEntry $entry): array => $this->timeRow($viewer, $entry))->values();
        $expenseRows = $expenses->take(self::LIMIT)->map(fn (ClientExpense $expense): array => $this->expenseRow($viewer, $expense))->values();

        return [
            'period' => $period->key(),
            'period_label' => $period->label(),
            'previous_period' => $period->previousKey(),
            'next_period' => $period->nextKey(),
            'time' => $timeRows->all(),
            'expenses' => $expenseRows->all(),
            'totals' => $this->totals($timeRows),
            'truncated' => $truncated,
        ];
    }

    /**
     * @param  Builder<ClientTimeEntry>|Builder<ClientExpense>  $query
     * @param  list<int>|null  $viewable
     */
    private function scoped(Builder $query, ?array $viewable, ?ClientProject $project): void
    {
        if ($viewable !== null) {
            $query->whereIn('client_project_id', $viewable);
        }
        if ($project instanceof ClientProject) {
            $query->where('client_project_id', $project->id);
        }
    }

    /** @return array<string, mixed> */
    private function timeRow(User $viewer, ClientTimeEntry $entry): array
    {
        $state = SubmissionState::of($entry->status, $entry->submitted_at !== null, $this->queue->verdictOfTime($entry));
        $refusal = SubmissionRules::timeRefusal(
            $entry->user_id, $viewer->id, $entry->status, $entry->submitted_at !== null,
            $entry->is_billable, $entry->is_visible_to_client && trim((string) $entry->client_visible_description) !== '', $entry->subcontractor_billing_mode,
        );

        return [
            'id' => $entry->public_id,
            'worked_on' => $entry->worked_on->toDateString(),
            'minutes' => $entry->minutes,
            'description' => $entry->description,
            'client_description' => $entry->client_visible_description,
            'project' => $entry->project?->name,
            'state' => $state->value,
            'dispute_note' => $state === SubmissionState::Disputed ? $entry->client_dispute_note : null,
            'can_submit' => $refusal === null,
            'why_not' => $refusal?->message(),
            'can_withdraw' => SubmissionRules::withdrawRefusal($entry->user_id, $viewer->id, $entry->status, $entry->submitted_at !== null, $entry->client_approved_at !== null) === null,
        ];
    }

    /** @return array<string, mixed> */
    private function expenseRow(User $viewer, ClientExpense $expense): array
    {
        $state = SubmissionState::of($expense->status, $expense->submitted_at !== null, $this->queue->verdictOfExpense($expense));
        $refusal = SubmissionRules::expenseRefusal($expense->created_by_user_id, $viewer->id, $expense->status, $expense->submitted_at !== null);

        return [
            'id' => $expense->public_id,
            'spent_on' => $expense->spent_on->toDateString(),
            'description' => $expense->description,
            'amount' => $expense->amount,
            'currency' => $expense->currency,
            'project' => $expense->project?->name,
            'state' => $state->value,
            'dispute_note' => $state === SubmissionState::Disputed ? $expense->client_dispute_note : null,
            'can_submit' => $refusal === null,
            'why_not' => $refusal?->message(),
            'can_withdraw' => SubmissionRules::withdrawRefusal($expense->created_by_user_id, $viewer->id, $expense->status, $expense->submitted_at !== null, $expense->client_approved_at !== null) === null,
        ];
    }

    /**
     * Minutes in each state, so the page can say "3.5 h ready to submit".
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function totals(Collection $rows): array
    {
        $totals = [];
        foreach (SubmissionState::cases() as $state) {
            $totals[$state->value] = (int) $rows->where('state', $state->value)->sum('minutes');
        }

        return $totals;
    }
}
