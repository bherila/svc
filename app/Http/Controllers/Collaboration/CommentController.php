<?php

namespace App\Http\Controllers\Collaboration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Collaboration\CollaborationReadRequest;
use App\Http\Requests\Collaboration\DeleteContentRequest;
use App\Models\ClientComment;
use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Collaboration\CollaborationAction;
use App\Services\Collaboration\CollaborationAudience;
use App\Services\Collaboration\CollaborationReadService;
use App\Services\Collaboration\CommentDraft;
use App\Services\Collaboration\ContentPatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CommentController extends Controller
{
    public function index(CollaborationReadRequest $request, Workspace $workspace, ClientCompany $clientCompany, string $parentType, string $parentPublicId, CollaborationReadService $read): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return Inertia::render('collaboration/thread', ['thread' => $read->thread($actor, $workspace, $clientCompany, $parentType, $parentPublicId, CollaborationAudience::Internal, (int) ($request->validated('comments_page') ?? 1), $request->validated('comment'), includeFiles: true)]);
    }

    public function store(Request $request, Workspace $workspace, ClientCompany $clientCompany, string $parentType, string $parentPublicId, CollaborationAction $action): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $comment = $action->createComment($actor, $workspace, $clientCompany, $parentType, $parentPublicId, CommentDraft::fromInput($request->all()), CollaborationAudience::Internal);
        $url = $parentType === 'topic'
            ? route('collaboration.show', ['workspace' => $workspace, 'clientCompany' => $clientCompany, 'topic' => $parentPublicId, 'comment' => $comment->public_id], absolute: false)
            : route('collaboration.comments.index', ['workspace' => $workspace, 'clientCompany' => $clientCompany, 'parentType' => $parentType, 'parentPublicId' => $parentPublicId, 'comment' => $comment->public_id], absolute: false);

        return redirect($url.'#comment-'.$comment->public_id)->with('status', 'Comment added.');
    }

    public function update(Request $request, Workspace $workspace, ClientCompany $clientCompany, ClientComment $comment, CollaborationAction $action): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->updateComment($actor, $workspace, $clientCompany, $comment->public_id, ContentPatch::fromInput($request->all(), false), CollaborationAudience::Internal);

        return back()->with('status', 'Comment saved.');
    }

    public function destroy(DeleteContentRequest $request, Workspace $workspace, ClientCompany $clientCompany, ClientComment $comment, CollaborationAction $action): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->deleteComment($actor, $workspace, $clientCompany, $comment->public_id, (string) $request->validated('expected_version'), CollaborationAudience::Internal);

        return back()->with('status', 'Comment removed.');
    }
}
