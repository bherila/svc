<?php

namespace App\Queries\Submission;

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientProjectParticipation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\ProjectAccess;
use App\Support\Approval\ApprovalPeriod;
use App\Support\WorkspaceClock;

/**
 * What a partner's light workspace shows on "My work" beyond tasks and time:
 * where the setup stands, and the month's hours on their way to approval
 * (docs/ux-overhaul.md section 7e).
 *
 * Everything is the viewer's own workspace's. The host is named only by the
 * name a participant is already told, and nothing else of the host's is read:
 * the participation row is reached through this workspace's own linked project.
 */
final class LightWorkspaceHome
{
    public function __construct(
        private readonly SubmissionsOverview $overview,
        private readonly ProjectAccess $access,
        private readonly WorkspaceClock $clock,
    ) {}

    /**
     * @return array{client: string|null, shared_project: array{name: string, host_workspace_name: string}|null, month: array{period: string, label: string, draft: int, submitted: int, approved: int, disputed: int}, submissions_href: string, timesheet_href: string, full_workspace_url: string|null}
     */
    public function for(Workspace $workspace, User $viewer): array
    {
        $period = ApprovalPeriod::containing($this->clock->today($workspace)->toDateString());
        $month = $this->overview->for($workspace, $viewer, $period);

        $client = ClientCompany::query()->where('workspace_id', $workspace->id)->orderBy('id')->value('name');
        $linked = ClientProject::query()->where('workspace_id', $workspace->id)->whereNotNull('shared_participation_id')->orderBy('id')->first();
        $participation = $linked === null ? null : ClientProjectParticipation::query()
            ->whereKey($linked->shared_participation_id)
            ->where('participant_workspace_id', $workspace->id)
            ->with('hostWorkspace:id,name')
            ->first();

        return [
            'client' => is_string($client) ? $client : null,
            'shared_project' => $linked === null || $participation === null ? null : [
                'name' => $linked->name,
                'host_workspace_name' => (string) $participation->hostWorkspace?->name,
            ],
            'month' => [
                'period' => $period->key(),
                'label' => $period->label(),
                'draft' => $month['totals']['draft'],
                'submitted' => $month['totals']['submitted'],
                'approved' => $month['totals']['approved'],
                'disputed' => $month['totals']['disputed'],
            ],
            'submissions_href' => route('workspaces.submissions', [$workspace, 'period' => $period->key()], absolute: false),
            'timesheet_href' => route('workspaces.timesheet', [$workspace, 'period' => $period->key()], absolute: false),
            'full_workspace_url' => $this->access->isWorkspaceManager($viewer, $workspace) ? route('workspaces.edition.full', $workspace, absolute: false) : null,
        ];
    }
}
