<?php

namespace App\Http\Controllers\Collaboration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Collaboration\CollaborationReadRequest;
use App\Http\Requests\Collaboration\DeleteContentRequest;
use App\Models\ClientCollaborationTopic;
use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Collaboration\CollaborationAction;
use App\Services\Collaboration\CollaborationAudience;
use App\Services\Collaboration\CollaborationReadService;
use App\Services\Collaboration\ContentPatch;
use App\Services\Collaboration\TopicDraft;
use App\Services\Collaboration\TopicKind;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function store(Request $request, Workspace $workspace, ClientCompany $clientCompany, CollaborationAction $action): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $topic = $action->createTopic($actor, $workspace, $clientCompany, TopicDraft::fromInput($request->all()), CollaborationAudience::Internal);

        return to_route('collaboration.show', [$workspace, $clientCompany, $topic]);
    }

    public function update(Request $request, Workspace $workspace, ClientCompany $clientCompany, ClientCollaborationTopic $topic, CollaborationAction $action): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->updateTopic($actor, $workspace, $clientCompany, $topic->public_id, ContentPatch::fromInput($request->all(), true), CollaborationAudience::Internal);

        return back()->with('status', 'Content saved.');
    }

    public function destroy(DeleteContentRequest $request, Workspace $workspace, ClientCompany $clientCompany, ClientCollaborationTopic $topic, CollaborationAction $action): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->deleteTopic($actor, $workspace, $clientCompany, $topic->public_id, (string) $request->validated('expected_version'), CollaborationAudience::Internal);

        return to_route('collaboration.index', [$workspace, $clientCompany])->with('status', 'Content removed.');
    }
}
