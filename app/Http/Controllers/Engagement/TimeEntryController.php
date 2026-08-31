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
use App\Services\Authorization\ProjectAccess;
use App\Services\Engagement\EngagementException;
use App\Services\Engagement\TimeEntryWorkflow;
use App\Services\WorkspaceAuthorization;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class TimeEntryController extends EngagementController
{
    /**
     * Log time against a project, on the same rule the agent API applies.
     *
     * This asked `manage` on the workspace - owners and admins - while
     * `TimeEntryMutationService::create()` asked `canLogTime()`, so a project
     * contributor could log time through a token and not through a browser
     * (#101). The time sheet worked around the disagreement rather than
     * resolving it: its `can_log_time` required both, so the screen simply did
     * not offer a form whose POST would have been refused.
     *
     * Both doors now ask `canLogTime()`. It is not a widening of who may write:
     * `projectRole()` resolves a workspace owner or admin to `ProjectRole::Owner`,
     * so everyone the old gate admitted still passes, and the people it turned
     * away could already write through the API.
     *
     * Whose time it is remains settled by the workflow, which takes the actor as
     * the worker. Neither door has ever accepted a subject, so a contributor
     * admitted here can log their own time and nobody else's.
     */
    public function store(
        StoreTimeEntryRequest $request,
        Workspace $workspace,
        ClientProject $clientProject,
        WorkspaceAuthorization $workspaceAuthorization,
        TimeEntryWorkflow $workflow,
        ProjectAccess $access,
    ): JsonResponse|RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        // Ownership before access: `canLogTime` reads the project's own
        // workspace, so asking it about a project from another tenant would
        // answer honestly about the wrong workspace.
        $workspaceAuthorization->assertOwnedBy($workspace, $clientProject);
        abort_unless($access->canLogTime($user, $clientProject), 403);

        $validated = $request->validated();
        $taskId = $validated['task_id'] ?? null;
        unset($validated['task_id']);

        // Logging work and pricing it are different decisions. A contributor
        // may record what they did; naming the rate it bills at is a
        // commercial term, and an explicit rate here is recorded as
        // `billing_rate_source => 'explicit'`, which outranks the rate the
        // agreement would have resolved. Restricted to whoever could approve
        // the entry afterwards, since that is the same judgement made earlier.
        //
        // A field error rather than a 403: the request is a legitimate one from
        // someone entitled to make it, with one field they may not set, and a
        // bare refusal would read as "you cannot log time here" - the very
        // confusion this endpoint just stopped causing.
        if (array_key_exists('billing_rate_amount', $validated)
            && $validated['billing_rate_amount'] !== null
            && ! $access->canApproveTime($user, $clientProject)) {
            throw ValidationException::withMessages([
                'billing_rate_amount' => 'Only a project manager can set the rate time bills at. Log the time and leave the rate to be resolved from the agreement.',
            ]);
        }

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
     * Change a draft entry, or approved time on a regenerable draft invoice.
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
