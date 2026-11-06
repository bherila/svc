<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Time\DurationStyle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The signed-in person's own display preferences.
 *
 * Writes only the caller's own row - there is no id in the route, so there is
 * nothing to point at someone else's - and only presentation: nothing here
 * changes what is stored or billed. A JSON endpoint called with `fetch` from the
 * account menu, not an Inertia mutation, and a named web-only exception in
 * WebApiParityTest: a display preference is not business data, so it has no
 * REST or MCP twin.
 */
class AccountPreferencesController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $user = $request->user('web');
        abort_unless($user instanceof User, 403);

        $validated = $request->validate([
            'duration_style' => ['required', 'string', Rule::enum(DurationStyle::class)],
        ]);

        $user->update(['duration_style' => $validated['duration_style']]);

        return response()->json(['data' => ['duration_style' => $user->duration_style->value]]);
    }
}
