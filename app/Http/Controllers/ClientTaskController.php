<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClientTaskRequest;
use App\Http\Requests\UpdateClientTaskRequest;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Tasks\TaskAccess;
use App\Services\Tasks\TaskCreateData;
use App\Services\Tasks\TaskEditorPresenter;
use App\Services\Tasks\TaskPatchData;
use App\Services\Tasks\WorkspaceTaskRepository;
use App\Services\WorkspaceAuthorization;
use App\Services\WorkspaceTaskMutationAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClientTaskController extends Controller
{
    public function show(Request $request, Workspace $workspace, ClientTask $clientTask, WorkspaceAuthorization $authorization, WorkspaceTaskMutationAction $tasks, TaskEditorPresenter $presenter): Response
    {
        $authorization->assertOwnedBy($workspace, $clientTask);
        $task = $tasks->find($workspace, $clientTask->public_id);
        $this->authorizeProject($request, $workspace, $task->project);
        $company = $task->project->clientCompany;

        return Inertia::render('clients/task', [
            'company' => ['name' => $company->name],
            'project' => ['name' => $task->project->name],
            'task' => $presenter->task($workspace, $task),
        ]);
    }

    public function assignees(Request $request, Workspace $workspace, ClientProject $clientProject, WorkspaceAuthorization $authorization, WorkspaceTaskRepository $tasks): JsonResponse
    {
        $authorization->assertOwnedBy($workspace, $clientProject);
        $data = $request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:100']]);
        $project = $tasks->project($workspace, $clientProject->public_id);
        $this->authorizeProject($request, $workspace, $project);

        return response()->json($tasks->assigneeOptions($workspace, $project, $data['q'] ?? ''));
    }

    public function store(
        StoreClientTaskRequest $request,
        Workspace $workspace,
        ClientProject $clientProject,
        WorkspaceAuthorization $authorization,
        WorkspaceTaskMutationAction $tasks,
    ): RedirectResponse {
        $authorization->assertOwnedBy($workspace, $clientProject);
        $this->authorizeProject($request, $workspace, $clientProject);

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $tasks->create($actor, $workspace, $clientProject->public_id, TaskCreateData::fromValidated($request->validated()));

        return redirect()->back()->with('status', 'Task created.');
    }

    public function update(
        UpdateClientTaskRequest $request,
        Workspace $workspace,
        ClientTask $clientTask,
        WorkspaceAuthorization $authorization,
        WorkspaceTaskMutationAction $tasks,
    ): RedirectResponse {
        $authorization->assertOwnedBy($workspace, $clientTask);

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $tasks->update($actor, $workspace, $clientTask->public_id, TaskPatchData::fromValidated($request->validated()), $request->validated('expected_version'));

        return redirect()->back()->with('status', 'Task updated.');
    }

    private function authorizeProject(Request $request, Workspace $workspace, ClientProject $project): void
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && app(TaskAccess::class)->canManage($actor, $workspace, $project), 403);
    }
}
