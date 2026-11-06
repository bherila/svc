<?php

namespace App\Http\Controllers\Submission;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Submission\SubmissionsOverview;
use App\Support\Approval\ApprovalPeriod;
use App\Support\WorkspaceClock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Submit for review": the signed-in member's own hours and expenses for one
 * calendar month, and the controls to submit them or take a submission back
 * (docs/ux-overhaul.md sections 7b and 7e).
 *
 * The controls post to the agent API, the same operations an agent uses; this
 * page only reads. Everything on it is the viewer's own, in projects they can
 * see, so any member may open it.
 */
final class SubmissionPageController extends Controller
{
    public function __invoke(Request $request, Workspace $workspace, SubmissionsOverview $overview, WorkspaceClock $clock): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        // Not a member is not found, as everywhere in the workspace tier.
        abort_unless(Gate::forUser($user)->allows('view', $workspace), 404);

        $period = ApprovalPeriod::fromKey((string) $request->query('period', ''))
            ?? ApprovalPeriod::containing($clock->today($workspace)->toDateString());
        $data = $overview->for($workspace, $user, $period);

        return Inertia::render('workspaces/submissions', [
            'workspace' => ['id' => $workspace->public_id, 'name' => $workspace->name],
            'submissions' => $data,
            'links' => [
                'submit_url' => route('agent-api.v1.submissions.submit', $workspace, absolute: false),
                'withdraw_url' => route('agent-api.v1.submissions.withdraw', $workspace, absolute: false),
                'previous_href' => route('workspaces.submissions', [$workspace, 'period' => $data['previous_period']], absolute: false),
                'next_href' => route('workspaces.submissions', [$workspace, 'period' => $data['next_period']], absolute: false),
                'timesheet_href' => route('workspaces.timesheet', [$workspace, 'period' => $data['period']], absolute: false),
                'my_work_href' => route('workspaces.my-work', $workspace, absolute: false),
            ],
        ]);
    }
}
