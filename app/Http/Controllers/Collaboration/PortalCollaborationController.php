<?php

namespace App\Http\Controllers\Collaboration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Collaboration\CollaborationReadRequest;
use App\Models\ClientCollaborationTopic;
use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Collaboration\CollaborationAudience;
use App\Services\Collaboration\CollaborationReadService;
use App\Services\Collaboration\TopicKind;
use Inertia\Inertia;
use Inertia\Response;

/** Read-only route family. Audience cannot be changed by query or submitted data. */
class PortalCollaborationController extends Controller
{
    public function index(CollaborationReadRequest $request, ClientCompany $clientCompany, CollaborationReadService $read): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $workspace = Workspace::query()->whereKey($clientCompany->workspace_id)->firstOrFail();

        return Inertia::render('collaboration/index', $read->listing($actor, $workspace, $clientCompany, CollaborationAudience::Portal,
            TopicKind::tryFrom((string) $request->validated('kind')), $request->validated('project_id'), (int) ($request->validated('page') ?? 1)));
    }

    public function show(CollaborationReadRequest $request, ClientCompany $clientCompany, ClientCollaborationTopic $topic, CollaborationReadService $read): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $workspace = Workspace::query()->whereKey($clientCompany->workspace_id)->firstOrFail();

        return Inertia::render('collaboration/show', $read->detail($actor, $workspace, $clientCompany, $topic->public_id, CollaborationAudience::Portal, (int) ($request->validated('comments_page') ?? 1), $request->validated('comment'), includeFiles: true));
    }

    public function comments(CollaborationReadRequest $request, ClientCompany $clientCompany, string $parentType, string $parentPublicId, CollaborationReadService $read): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $workspace = Workspace::query()->whereKey($clientCompany->workspace_id)->firstOrFail();

        return Inertia::render('collaboration/thread', ['thread' => $read->thread($actor, $workspace, $clientCompany, $parentType, $parentPublicId, CollaborationAudience::Portal, (int) ($request->validated('comments_page') ?? 1), $request->validated('comment'), includeFiles: true)]);
    }
}
