<?php

namespace App\Http\Controllers\Submission;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Submission\ApprovedTimesheet;
use App\Services\Submission\ApprovedTimesheetPdf;
use App\Support\Approval\ApprovalPeriod;
use App\Support\Submission\TimesheetCsv;
use App\Support\WorkspaceClock;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The approved timesheet for a calendar month, on screen and as a file
 * (docs/ux-overhaul.md section 7e): the hours a reviewer or the client's
 * contact approved, which the provider can invoice from or send.
 *
 * Any member may open it; the query shows an owner or admin the workspace's
 * hours and any other member their own.
 */
final class ApprovedTimesheetController extends Controller
{
    public function __construct(
        private readonly ApprovedTimesheet $timesheet,
        private readonly WorkspaceClock $clock,
    ) {}

    public function show(Request $request, Workspace $workspace): Response
    {
        [$user, $period] = $this->resolve($request, $workspace);
        $data = $this->timesheet->for($workspace, $user, $period);

        return Inertia::render('workspaces/timesheet', [
            'workspace' => ['id' => $workspace->public_id, 'name' => $workspace->name],
            'timesheet' => $data,
            'links' => [
                'previous_href' => route('workspaces.timesheet', [$workspace, 'period' => $data['previous_period']], absolute: false),
                'next_href' => route('workspaces.timesheet', [$workspace, 'period' => $data['next_period']], absolute: false),
                'csv_href' => route('workspaces.timesheet.export', [$workspace, 'period' => $data['period'], 'format' => 'csv'], absolute: false),
                'pdf_href' => route('workspaces.timesheet.export', [$workspace, 'period' => $data['period'], 'format' => 'pdf'], absolute: false),
                'submissions_href' => route('workspaces.submissions', [$workspace, 'period' => $data['period']], absolute: false),
            ],
        ]);
    }

    public function export(Request $request, Workspace $workspace, ApprovedTimesheetPdf $pdf): HttpResponse
    {
        [$user, $period] = $this->resolve($request, $workspace);
        $format = $request->query('format');
        abort_unless(in_array($format, ['csv', 'pdf'], true), 404);
        $data = $this->timesheet->for($workspace, $user, $period);
        $name = 'approved-timesheet-'.$period->key().'.'.$format;

        $body = $format === 'csv' ? TimesheetCsv::render($data['entries']) : $pdf->render((string) $workspace->name, $data);

        return response($body, 200, [
            'Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array{User, ApprovalPeriod} */
    private function resolve(Request $request, Workspace $workspace): array
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        // Not a member is not found, as everywhere in the workspace tier.
        abort_unless(Gate::forUser($user)->allows('view', $workspace), 404);
        $period = ApprovalPeriod::fromKey((string) $request->query('period', ''))
            ?? ApprovalPeriod::containing($this->clock->today($workspace)->toDateString());

        return [$user, $period];
    }
}
