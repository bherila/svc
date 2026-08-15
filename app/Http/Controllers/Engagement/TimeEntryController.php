<?php

namespace App\Http\Controllers\Engagement;

use App\Http\Requests\Engagement\StoreTimeEntryRequest;
use App\Models\ClientProject;
use App\Models\Workspace;
use App\Services\Engagement\EngagementAuthorization;
use App\Services\Engagement\EngagementException;
use App\Services\Engagement\TimeEntryWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class TimeEntryController extends EngagementController
{
    public function store(
        StoreTimeEntryRequest $request,
        Workspace $workspace,
        ClientProject $clientProject,
        EngagementAuthorization $authorization,
        TimeEntryWorkflow $workflow,
    ): JsonResponse|RedirectResponse {
        $user = $request->user();
        abort_if($user === null, 401);
        abort_unless($authorization->canManage($user, $workspace), 403);
        abort_unless($clientProject->workspace_id === $workspace->id, 404);

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
