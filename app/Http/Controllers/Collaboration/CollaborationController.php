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

class CollaborationController extends Controller
{
    public function index(CollaborationReadRequest $request, Workspace $workspace, ClientCompany $clientCompany, CollaborationReadService $read): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return Inertia::render('collaboration/index', $read->listing($actor, $workspace, $clientCompany, CollaborationAudience::Internal,
            TopicKind::tryFrom((string) $request->validated('kind')), $request->validated('project_id'), (int) ($request->validated('page') ?? 1)));
    }

    public function show(CollaborationReadRequest $request, Workspace $workspace, ClientCompany $clientCompany, ClientCollaborationTopic $topic, CollaborationReadService $read): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return Inertia::render('collaboration/show', $read->detail($actor, $workspace, $clientCompany, $topic->public_id, CollaborationAudience::Internal, (int) ($request->validated('comments_page') ?? 1), $request->validated('comment'), includeFiles: true));
    }
}
