<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $workspaces = $user->workspaces()
            ->with(['clientCompanies' => fn ($query) => $query->orderBy('name'), 'clientCompanies.projects.tasks'])
            ->orderBy('name')
            ->get();

        $workspacePayload = [];

        foreach ($workspaces as $workspace) {
            $clientPayload = [];

            foreach ($workspace->clientCompanies as $company) {
                $projectPayload = [];

                foreach ($company->projects as $project) {
                    $taskPayload = [];

                    foreach ($project->tasks as $task) {
                        $taskPayload[] = [
                            'id' => $task->public_id,
                            'title' => $task->title,
                            'status' => $task->status,
                            'is_visible_to_client' => $task->is_visible_to_client,
                        ];
                    }

                    $projectPayload[] = [
                        'id' => $project->public_id,
                        'name' => $project->name,
                        'status' => $project->status,
                        'is_visible_to_client' => $project->is_visible_to_client,
                        'tasks' => $taskPayload,
                    ];
                }

                $clientPayload[] = [
                    'id' => $company->public_id,
                    'name' => $company->name,
                    'billing_email' => $company->billing_email,
                    'portal_url' => route('portal.show', $company),
                    'projects' => $projectPayload,
                ];
            }

            $role = $workspace->memberships()->where('user_id', $user->id)->value('role');
            $workspacePayload[] = [
                'id' => $workspace->public_id,
                'name' => $workspace->name,
                'role' => is_string($role) ? $role : 'member',
                'operations_url' => route('workspaces.operations', $workspace),
                'time_url' => route('svc.engagement.time-entries.index', $workspace),
                'clients' => $clientPayload,
            ];
        }

        return Inertia::render('dashboard', ['workspaces' => $workspacePayload]);
    }
}
