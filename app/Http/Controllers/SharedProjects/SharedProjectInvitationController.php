<?php

namespace App\Http\Controllers\SharedProjects;

use App\Http\Controllers\Controller;
use App\Models\ClientProjectParticipationInvitation;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\SharedProjects\SharedProjectParticipations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Accepting or declining an invitation to take part in another workspace's project.
 *
 * Reached by link, outside any workspace: the invitation is addressed to a
 * person, so it is read only together with the signed-in person whose address
 * matches. Anyone else - another address, a closed or expired invitation, a
 * made-up id - gets the same 404. Accepting names which of the person's own
 * workspaces joins, and only one they own or administer is offered.
 */
final class SharedProjectInvitationController extends Controller
{
    public function __construct(private readonly SharedProjectParticipations $participations) {}

    public function show(Request $request, string $invitation): InertiaResponse
    {
        $user = $this->user($request);
        $record = $this->participations->openInvitationFor($user, $invitation);
        abort_if($record === null, 404);

        $workspaces = Workspace::query()
            ->whereIn('id', WorkspaceMembership::query()->where('user_id', $user->id)->whereIn('role', ['owner', 'admin'])->select('workspace_id'))
            ->where('id', '!=', $record->host_workspace_id)
            ->orderBy('name')->orderBy('id')->limit(50)->get();

        return Inertia::render('shared-project-invitations/show', [
            'invitation' => $this->present($record),
            'workspaces' => $workspaces->map(fn (Workspace $workspace): array => ['id' => $workspace->public_id, 'name' => $workspace->name])->values()->all(),
            'accept_url' => route('shared-project-invitations.accept', $record->public_id, absolute: false),
            'decline_url' => route('shared-project-invitations.decline', $record->public_id, absolute: false),
            'workspaces_url' => route('workspaces.index', absolute: false),
        ]);
    }

    public function accept(Request $request, string $invitation): JsonResponse
    {
        $user = $this->user($request);
        $input = $request->validate(['workspace_id' => ['required', 'uuid']]);
        $workspace = Workspace::query()->where('public_id', $input['workspace_id'])->first();
        // A workspace the person is not in is not found, the same as one that does not exist.
        abort_unless($workspace !== null && WorkspaceMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $user->id)->exists(), 404);

        $participation = $this->participations->accept($user, $invitation, $workspace);

        return response()->json(['data' => [
            'redirect_url' => route('shared-projects.show', [$workspace, $participation->project->public_id], absolute: false),
        ]]);
    }

    public function decline(Request $request, string $invitation): JsonResponse
    {
        $this->participations->decline($this->user($request), $invitation);

        return response()->json(['data' => ['redirect_url' => route('workspaces.index', absolute: false)]]);
    }

    /** @return array<string, mixed> */
    private function present(ClientProjectParticipationInvitation $invitation): array
    {
        return [
            'id' => $invitation->public_id,
            'project_name' => $invitation->project->name,
            'host_workspace_name' => $invitation->hostWorkspace->name,
            'email' => $invitation->email,
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
