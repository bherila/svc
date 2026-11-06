<?php

namespace App\Http\Controllers\Submission;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Access\AccessAuditLog;
use App\Support\Access\AccessAuditAction;
use App\Support\Access\AccessMutationContext;
use App\Support\Workspaces\WorkspaceEdition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Turning a light workspace into a full one (docs/ux-overhaul.md section 7e).
 *
 * An owner's or admin's choice, one way, and a browser action: it switches on
 * agreements, invoicing and revenue for the whole business, which is not
 * something an agent decides. Repeating it on a full workspace is a harmless
 * no-op.
 */
final class WorkspaceEditionController extends Controller
{
    public function full(Request $request, Workspace $workspace, AccessAuditLog $audit): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless(Gate::forUser($user)->allows('manage', $workspace), 404);

        if (WorkspaceEdition::of($workspace->getAttribute('edition'))->isLight()) {
            $workspace->forceFill(['edition' => WorkspaceEdition::Full->value])->save();
            $audit->record(AccessMutationContext::user($user), $workspace, AccessAuditAction::WorkspaceEditionChanged, null, ['edition' => WorkspaceEdition::Light->value], ['edition' => WorkspaceEdition::Full->value]);
        }

        return response()->json(['data' => ['redirect_url' => route('workspaces.enter', $workspace, absolute: false)]]);
    }
}
