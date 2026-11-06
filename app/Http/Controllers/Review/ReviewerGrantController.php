<?php

namespace App\Http\Controllers\Review;

use App\Http\Controllers\Controller;
use App\Models\ReviewerGrant;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Review\ReviewerGrantsOverview;
use App\Services\Authorization\ProjectAccess;
use App\Services\Review\ReviewerGrants;
use App\Support\Access\StaffRole;
use App\Support\Review\GranteeKind;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * The page where a provider gives and takes back reviewer grants, and the two
 * browser-only changes behind it.
 *
 * Letting someone outside the business vouch for its hours is a person's
 * decision, so give and revoke are routes here and nowhere in REST or MCP
 * (docs/ux-overhaul.md, slice 10). Only the workspace's owners and admins may
 * open the page or change a grant; a plain member is refused and a stranger
 * gets a 404. The page carries finished URLs and no ids to assemble.
 */
final class ReviewerGrantController extends Controller
{
    public function __construct(
        private readonly ReviewerGrants $grants,
        private readonly ReviewerGrantsOverview $overview,
        private readonly ProjectAccess $roles,
    ) {}

    public function show(Request $request, Workspace $workspace): InertiaResponse
    {
        $user = $this->user($request);
        $role = StaffRole::tryFrom((string) $this->roles->workspaceRole($user, $workspace));
        abort_if($role === null, 404);
        abort_unless($role->managesWorkspace(), 403);

        return Inertia::render('workspaces/reviewers', [
            'workspaceName' => $workspace->name,
            'store_url' => route('workspaces.reviewers.store', $workspace, absolute: false),
        ] + $this->overview->for($workspace));
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $input = $request->validate([
            'client_id' => ['required', 'uuid'],
            'project_id' => ['nullable', 'uuid'],
            'kind' => ['required', 'in:contact,workspace'],
            'contact_id' => ['required_if:kind,contact', 'nullable', 'uuid'],
            'workspace_id' => ['required_if:kind,workspace', 'nullable', 'uuid'],
        ]);
        $kind = GranteeKind::from($input['kind']);
        $grant = $this->grants->grant(
            $this->user($request),
            $workspace,
            $input['client_id'],
            $input['project_id'] ?? null,
            $kind,
            (string) ($kind === GranteeKind::Contact ? $input['contact_id'] : $input['workspace_id']),
        );

        return response()->json(['data' => $this->present($workspace, $grant)], 201);
    }

    public function destroy(Request $request, Workspace $workspace, string $grant): Response
    {
        $this->grants->revoke($this->user($request), $workspace, $grant);

        return response()->noContent();
    }

    /** @return array{id: string, revoke_url: string} */
    private function present(Workspace $workspace, ReviewerGrant $grant): array
    {
        return [
            'id' => $grant->public_id,
            'revoke_url' => route('workspaces.reviewers.destroy', [$workspace, $grant->public_id], absolute: false),
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
