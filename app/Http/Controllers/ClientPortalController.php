<?php

namespace App\Http\Controllers;

use App\Models\ClientCompany;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ClientPortalController extends Controller
{
    public function show(ClientCompany $clientCompany): Response
    {
        Gate::authorize('viewPortal', $clientCompany);

        $clientCompany->load(['projects' => function ($query): void {
            $query->where('is_visible_to_client', true)
                ->with(['tasks' => fn ($taskQuery) => $taskQuery->where('is_visible_to_client', true)]);
        }]);

        $projectPayload = [];

        foreach ($clientCompany->projects as $project) {
            $taskPayload = [];

            foreach ($project->tasks as $task) {
                $taskPayload[] = [
                    'id' => $task->public_id,
                    'title' => $task->title,
                    'description' => $task->description,
                    'status' => $task->status,
                ];
            }

            $projectPayload[] = [
                'id' => $project->public_id,
                'name' => $project->name,
                'description' => $project->description,
                'status' => $project->status,
                'tasks' => $taskPayload,
            ];
        }

        return Inertia::render('portal', [
            'company' => [
                'name' => $clientCompany->name,
                'projects' => $projectPayload,
            ],
        ]);
    }
}
