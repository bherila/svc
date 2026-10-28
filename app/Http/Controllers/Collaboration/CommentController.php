<?php

namespace App\Http\Controllers\Collaboration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Collaboration\CollaborationReadRequest;
use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Collaboration\CollaborationAudience;
use App\Services\Collaboration\CollaborationReadService;
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
}
