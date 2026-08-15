<?php

namespace App\Http\Controllers\Engagement;

use App\Http\Requests\Engagement\StoreTimeEntryRequest;
use App\Models\ClientProject;
use App\Models\Workspace;
use App\Services\Engagement\EngagementException;
use App\Services\Engagement\TimeEntryWorkflow;
use App\Services\WorkspaceAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class TimeEntryController extends EngagementController
{
    public function store(
        StoreTimeEntryRequest $request,
        Workspace $workspace,
        ClientProject $clientProject,
        WorkspaceAuthorization $workspaceAuthorization,
        TimeEntryWorkflow $workflow,
    ): JsonResponse|RedirectResponse {
        $user = $request->user();
        abort_if($user === null, 401);
        Gate::forUser($user)->authorize('manage', $workspace);
        $workspaceAuthorization->assertOwnedBy($workspace, $clientProject);

        try {
            $entry = $workflow->create($workspace, $clientProject, $user, $request->validated());

            return $this->respond(
                $request,
                ['data' => $entry],
                'svc.engagement.time-entries.store',
                'Time entry logged.',
                201,
            );
        } catch (EngagementException $exception) {
            return $this->reportFailure($request, $exception);
        }
    }
}
