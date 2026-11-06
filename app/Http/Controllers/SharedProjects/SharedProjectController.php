<?php

namespace App\Http\Controllers\SharedProjects;

use App\Http\Controllers\Controller;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SharedProjects\SharedProjectAccess;
use App\Services\SharedProjects\SharedProjectParticipations;
use App\Services\SharedProjects\SharedProjectReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * The shared project screens, and the browser-only changes to who takes part.
 *
 * Handing another business access to a project is a person's decision, so the
 * invite, revoke, leave and link actions are routes here and nowhere in REST or
 * MCP (docs/ux-overhaul.md, slice 9). The reads and the two collaboration
 * writes - assign and comment - are the shared REST operations, which the page
 * calls with its session like every other page.
 *
 * Every action resolves the project through {@see SharedProjectAccess}: a
 * workspace that is neither the host nor an active participant gets 404.
 */
final class SharedProjectController extends Controller
{
    public function __construct(
        private readonly SharedProjectAccess $access,
        private readonly SharedProjectReadService $reads,
        private readonly SharedProjectParticipations $participations,
    ) {}

    public function index(Request $request, Workspace $workspace): InertiaResponse
    {
        $user = $this->user($request);
        abort_unless($workspace->memberships()->where('user_id', $user->id)->exists(), 404);

        return Inertia::render('shared-projects/index', ['workspaceName' => $workspace->name] + $this->reads->index($user, $workspace));
    }

    public function show(Request $request, Workspace $workspace, string $sharedProject): InertiaResponse
    {
        $user = $this->user($request);
        $context = $this->access->resolve($user, $workspace, $sharedProject);
        abort_if($context === null, 404);
        abort_unless($context->viewer->canViewProject(), 404);

        $project = $this->reads->project($context, $user);
        $tasks = $this->reads->tasks($context, $user);
        $viewer = $context->viewer;
        $parameters = [$workspace, $context->project->public_id];

        $localOptions = [];
        if ($viewer->canActForParticipant()) {
            $localOptions = ClientProject::query()->where('workspace_id', $workspace->id)
                ->where(fn ($query) => $query->whereNull('shared_participation_id')->orWhere('shared_participation_id', $context->participation?->id))
                ->orderBy('name')->orderBy('id')->limit(SharedProjectReadService::OPTIONS)->get(['public_id', 'name'])
                ->map(fn (ClientProject $local): array => ['id' => $local->public_id, 'name' => $local->name])->all();
        }

        $project['participants'] = array_map(fn (array $row): array => $row + [
            'revoke_url' => $viewer->canManageParticipants() ? route('shared-projects.participants.destroy', [...$parameters, $row['id']], absolute: false) : null,
        ], $project['participants'] ?? []);
        $project['invitations'] = array_map(fn (array $row): array => $row + [
            'accept_url' => route('shared-project-invitations.show', $row['id'], absolute: false),
            'revoke_url' => route('shared-projects.invitations.destroy', [...$parameters, $row['id']], absolute: false),
        ], $project['invitations'] ?? []);

        return Inertia::render('shared-projects/show', [
            'workspaceName' => $workspace->name,
            'project' => $project,
            'tasks' => array_map(fn (array $task): array => $task + [
                'comments_url' => route('agent-api.v1.shared-projects.comments.index', [...$parameters, $task['id']], absolute: false),
                'assign_url' => $viewer->canAssign() ? route('agent-api.v1.shared-projects.tasks.assignees.update', [...$parameters, $task['id']], absolute: false) : null,
            ], $tasks['data']),
            'tasks_next_cursor' => $tasks['meta']['next_cursor'],
            'tasks_url' => route('agent-api.v1.shared-projects.tasks.index', $parameters, absolute: false),
            'urls' => [
                'index' => route('shared-projects.index', $workspace, absolute: false),
                'invite' => $viewer->canManageParticipants() ? route('shared-projects.invitations.store', $parameters, absolute: false) : null,
                'leave' => $viewer->canActForParticipant() ? route('shared-projects.leave', $parameters, absolute: false) : null,
                'link' => $viewer->canActForParticipant() ? route('shared-projects.local-project.update', $parameters, absolute: false) : null,
                'host_project' => $context->isHost() ? route('clients.project', [$workspace, $context->project->clientCompany, $context->project], absolute: false) : null,
            ],
            'local_project_options' => $localOptions,
        ]);
    }

    public function invite(Request $request, Workspace $workspace, string $sharedProject): JsonResponse
    {
        $input = $request->validate(['email' => ['required', 'string', 'max:255']]);
        $invitation = $this->participations->invite($this->user($request), $workspace, $sharedProject, (string) $input['email']);

        return response()->json(['data' => [
            'id' => $invitation->public_id,
            'email' => $invitation->email,
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'accept_url' => route('shared-project-invitations.show', $invitation->public_id),
        ]], 201);
    }

    public function revokeInvitation(Request $request, Workspace $workspace, string $sharedProject, string $invitation): Response
    {
        $this->participations->revokeInvitation($this->user($request), $workspace, $sharedProject, $invitation);

        return response()->noContent();
    }

    public function revokeParticipant(Request $request, Workspace $workspace, string $sharedProject, string $participation): Response
    {
        $this->participations->revokeParticipant($this->user($request), $workspace, $sharedProject, $participation);

        return response()->noContent();
    }

    public function leave(Request $request, Workspace $workspace, string $sharedProject): Response
    {
        $this->participations->leave($this->user($request), $workspace, $sharedProject);

        return response()->noContent();
    }

    public function link(Request $request, Workspace $workspace, string $sharedProject): Response
    {
        $input = $request->validate(['local_project_id' => ['present', 'nullable', 'uuid']]);
        $this->participations->linkLocalProject($this->user($request), $workspace, $sharedProject, $input['local_project_id']);

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
