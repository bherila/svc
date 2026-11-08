<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Collaboration\CollaborationAudience;
use App\Services\Search\PaletteActions;
use App\Services\Search\PaletteAudiences;
use App\Services\Search\PaletteRecents;
use App\Support\Search\PaletteAction;
use App\Support\Search\SearchResult;
use App\Support\Search\SearchResultKind;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the command palette shows before anything is typed: the commands this
 * viewer may carry out and the records they opened last.
 *
 * Like {@see SearchController} it is not workspace-scoped in the route - the
 * palette opens on screens that are in no workspace - so the page the viewer is
 * on arrives as a path and is matched against the workspaces they belong to
 * ({@see PaletteAudiences}). A path naming someone else's workspace resolves to
 * nothing, which is the same answer as a workspace that does not exist.
 */
class PaletteController extends Controller
{
    public function show(Request $request, PaletteAudiences $audiences, PaletteActions $actions, PaletteRecents $recents): JsonResponse
    {
        $validated = $request->validate(['path' => ['nullable', 'string', 'max:300']]);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $context = $audiences->resolve($user, (string) ($validated['path'] ?? ''));

        if ($context === null) {
            // No workspace to speak of (the selector, the account pages, a
            // viewer with several workspaces on a screen that names none):
            // only what is true everywhere, and recents across all of theirs.
            $any = $audiences->for($user) !== [];

            return response()->json([
                'audience' => $any ? 'workspace' : null,
                'workspace' => null,
                'actions' => $any ? array_map(fn (PaletteAction $action): array => $action->toArray(), PaletteActions::accountLevel()) : [],
                'recents' => $this->rows($recents->for($user)),
            ]);
        }

        [$workspace, $audience, $company] = $context;

        return response()->json([
            'audience' => $audience === CollaborationAudience::Portal ? 'portal' : 'workspace',
            'workspace' => ['id' => $workspace->public_id, 'name' => $workspace->name],
            'actions' => array_map(fn (PaletteAction $action): array => $action->toArray(), $actions->for($user, $workspace, $audience, $company)),
            'recents' => $this->rows($recents->for($user, $workspace)),
        ]);
    }

    public function record(Request $request, PaletteRecents $recents): JsonResponse
    {
        $validated = $request->validate([
            'workspace_id' => ['required', 'uuid'],
            'kind' => ['required', 'string', 'in:'.implode(',', array_map(fn (SearchResultKind $kind): string => $kind->value, SearchResultKind::recentable()))],
            'id' => ['required', 'uuid'],
        ]);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $recorded = $recents->record($user, strtolower($validated['workspace_id']), SearchResultKind::from($validated['kind']), strtolower($validated['id']));

        return response()->json(['recorded' => $recorded]);
    }

    /**
     * @param  list<SearchResult>  $results
     * @return list<array{kind: string, id: string, title: string, subtitle: string|null, href: string, workspace: string, workspace_id: string, heading: string, recent: bool}>
     */
    private function rows(array $results): array
    {
        return array_map(fn (SearchResult $result): array => $result->toPaletteArray(), $results);
    }
}
