<?php

namespace App\Http\Controllers\Engagement;

use App\Http\Requests\Engagement\ApproveTimeEntriesRequest;
use App\Http\Requests\Engagement\StoreTimeEntryRequest;
use App\Http\Requests\Engagement\UpdateTimeEntryRequest;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\TimeEntryMutationService;
use App\Services\Engagement\EngagementException;
use App\Services\Engagement\TimeEntryWorkflow;
use App\Services\WorkspaceAuthorization;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
        abort_unless($user instanceof User, 401);
        Gate::forUser($user)->authorize('manage', $workspace);
        $workspaceAuthorization->assertOwnedBy($workspace, $clientProject);

        $validated = $request->validated();
        $taskId = $validated['task_id'] ?? null;
        unset($validated['task_id']);

        // The workflow has always accepted a task; nothing on the web could
        // reach it, so every entry logged from a browser was unattributed.
        $task = is_string($taskId) && $taskId !== ''
            ? ClientTask::query()
                ->where('workspace_id', $workspace->id)
                ->where('public_id', $taskId)
                ->firstOrFail()
            : null;

        try {
            $entry = $workflow->create($workspace, $clientProject, $user, $validated, $task);

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

    /**
     * Change a draft entry.
     *
     * The rules that decide whether an entry may change at all - draft only,
     * the actor's own row or a manager's, a client-visible entry needing a
     * client-facing description, and the optimistic version - live in the
     * mutation service the agent API already uses. The operator screen goes
     * through the same door rather than restating them.
     */
    public function update(
        UpdateTimeEntryRequest $request,
        Workspace $workspace,
        ClientTimeEntry $timeEntry,
        TimeEntryMutationService $entries,
    ): JsonResponse|RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        try {
            $entry = $entries->update($workspace, $timeEntry, $user, $request->validated());
        } catch (DomainException $exception) {
            return $this->reportFailure($request, new EngagementException($exception->getMessage()));
        } catch (HttpExceptionInterface $exception) {
            return $this->reportConflict($request, $exception);
        }

        return $this->respond(
            $request,
            ['data' => $entry],
            'svc.engagement.time-entries.update',
            'Time entry updated.',
        );
    }

    public function destroy(
        Request $request,
        Workspace $workspace,
        ClientTimeEntry $timeEntry,
        TimeEntryMutationService $entries,
    ): JsonResponse|RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $validated = $request->validate(['expected_version' => ['required', 'string']]);

        try {
            $entries->delete($workspace, $timeEntry, $user, $validated['expected_version']);
        } catch (HttpExceptionInterface $exception) {
            return $this->reportConflict($request, $exception);
        }

        return $this->respond(
            $request,
            ['data' => null],
            'svc.engagement.time-entries.destroy',
            'Time entry deleted.',
        );
    }

    /**
     * Approve one or more draft entries.
     *
     * Approval is where a rate is stamped, so it is the one action here that
     * changes what an invoice will charge. The service resolves the agreement
     * rate unless both halves of an override are supplied.
     */
    public function approve(
        ApproveTimeEntriesRequest $request,
        Workspace $workspace,
        TimeEntryMutationService $entries,
    ): JsonResponse|RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        Gate::forUser($user)->authorize('view', $workspace);

        /** @var array{entries: list<array{id: string, expected_version: string, billing_rate_amount?: int, currency?: string}>} $validated */
        $validated = $request->validated();

        try {
            $entries->approve($workspace, $user, $validated['entries']);
        } catch (DomainException $exception) {
            return $this->reportFailure($request, new EngagementException($exception->getMessage()));
        } catch (HttpExceptionInterface $exception) {
            return $this->reportConflict($request, $exception);
        }

        return $this->respond(
            $request,
            ['data' => null],
            'svc.engagement.time-entries.approve',
            'Time approved.',
        );
    }
}
