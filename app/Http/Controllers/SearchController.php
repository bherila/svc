<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Search\WorkspaceSearch;
use App\Support\Search\SearchResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the command palette asks as you type.
 *
 * Deliberately not workspace-scoped in the route. The palette is reachable
 * from anywhere in the application, including the dashboard, which is not
 * inside a workspace at all - so the workspace cannot come from the path. It
 * comes from the person instead: {@see WorkspaceSearch} searches exactly the
 * workspaces they are a member of, and a viewer with none gets an empty list
 * rather than a 403, because "nothing matched" is the true answer for someone
 * with nothing to search.
 *
 * A portal user has client company memberships and no workspace membership, so
 * this returns nothing for them by the same rule. That is the intended
 * behaviour and not an omission: the palette is operator navigation, and the
 * portal has its own screens.
 */
class SearchController extends Controller
{
    public function __invoke(Request $request, WorkspaceSearch $search): JsonResponse
    {
        $validated = $request->validate([
            // Bounded because it reaches a LIKE. Not `nullable`: an absent key
            // and an empty string both mean "nothing typed yet", and the
            // service already returns nothing for a blank term - but a rule
            // that accepted an absent key would let a malformed request
            // through as a successful empty search, which is the silent
            // absorption this codebase keeps finding.
            'q' => ['required', 'string', 'max:200'],
        ]);

        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['results' => []]);
        }

        $results = $search->forUser($user, $validated['q']);

        return response()->json([
            'results' => array_map(
                static fn (SearchResult $result): array => $result->toArray(),
                $results,
            ),
        ]);
    }
}
