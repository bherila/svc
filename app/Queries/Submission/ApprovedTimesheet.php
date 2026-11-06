<?php

namespace App\Queries\Submission;

use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Approval\PortalApprovalQueue;
use App\Services\Authorization\ProjectAccess;
use App\Support\Approval\ApprovalPeriod;
use App\Support\Approval\ClientApprovalSource;
use App\Support\Submission\TimesheetRules;
use Illuminate\Database\Eloquent\Builder;

/**
 * The approved timesheet: the hours someone other than their author approved,
 * for one calendar month, with who worked them and who approved each
 * (docs/ux-overhaul.md section 7e). It is what a provider invoices from or
 * sends.
 *
 * Only rows with a person's approval in force are on it. A submitted row nobody
 * has decided, a disputed row, a draft and an approval implied by payment are
 * not, however the month looks otherwise. No rate, amount, internal note or
 * invoice is read, so there is none to print; the words are the client-facing
 * description the approver read.
 *
 * Scoped like everything a member reads here: the workspace, and the projects
 * the viewer can see. A member who is not an owner or admin sees their own
 * hours only; an owner or admin sees the workspace's.
 */
final class ApprovedTimesheet
{
    public const int LIMIT = 1000;

    public function __construct(
        private readonly ProjectAccess $access,
        private readonly PortalApprovalQueue $queue,
    ) {}

    /**
     * @return array{period: string, period_label: string, previous_period: string, next_period: string, entries: list<array<string, mixed>>, total_minutes: int, truncated: bool}
     */
    public function for(Workspace $workspace, User $viewer, ApprovalPeriod $period, ?string $projectPublicId = null): array
    {
        $viewable = $this->access->viewableProjectIds($viewer, $workspace);
        $project = $projectPublicId === null ? null : ClientProject::query()->where('workspace_id', $workspace->id)->where('public_id', $projectPublicId)->first();
        abort_if($projectPublicId !== null && $project === null, 404);

        $rows = ClientTimeEntry::query()
            ->where('workspace_id', $workspace->id)
            ->whereBetween('worked_on', [$period->start(), $period->end()])
            ->whereNotNull('client_approved_at')
            ->whereNull('client_disputed_at')
            ->whereIn('client_approval_source', [ClientApprovalSource::Portal->value, ClientApprovalSource::Reviewer->value])
            ->when($viewable !== null, fn (Builder $query) => $query->whereIn('client_project_id', $viewable ?? [])->where('user_id', $viewer->id))
            ->when($project instanceof ClientProject, fn (Builder $query) => $query->where('client_project_id', $project?->id))
            ->with(['project:id,name', 'task:id,title', 'invoiceLines:id,client_invoice_id', 'invoiceLines.invoice:id,workspace_id,status'])
            ->orderBy('worked_on')->orderBy('id')
            ->limit(self::LIMIT + 1)
            ->get()
            ->filter(fn (ClientTimeEntry $entry): bool => TimesheetRules::includes($this->queue->verdictOfTime($entry)))
            ->values();

        $truncated = $rows->count() > self::LIMIT;
        $rows = $rows->take(self::LIMIT);

        // Names come from `users`, which holds no tenant, so the ids are the
        // ones these workspace-scoped rows carry and nothing else.
        $people = User::query()
            ->whereIn('id', $rows->pluck('user_id')->merge($rows->pluck('client_approved_by_user_id'))->filter()->unique()->all())
            ->pluck('name', 'id');

        $entries = $rows->map(fn (ClientTimeEntry $entry): array => [
            'id' => $entry->public_id,
            'worked_on' => $entry->worked_on->toDateString(),
            'minutes' => $entry->minutes,
            'description' => (string) $entry->client_visible_description,
            'project' => $entry->project?->name,
            'task' => $entry->task?->title,
            'worked_by' => $entry->user_id === null ? null : ($people[$entry->user_id] ?? null),
            'approved_by' => $entry->client_approved_by_user_id === null ? null : ($people[$entry->client_approved_by_user_id] ?? null),
            'approved_at' => $entry->client_approved_at?->toIso8601String(),
            'approved_via' => TimesheetRules::via($entry->client_approval_source),
        ])->values();

        return [
            'period' => $period->key(),
            'period_label' => $period->label(),
            'previous_period' => $period->previousKey(),
            'next_period' => $period->nextKey(),
            'entries' => $entries->all(),
            'total_minutes' => (int) $entries->sum('minutes'),
            'truncated' => $truncated,
        ];
    }
}
