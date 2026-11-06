<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Time\DurationStyle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The signed-in person's own display preferences.
 *
 * Writes only the caller's own row - there is no id in the route, so there is
 * nothing to point at someone else's - and only presentation: nothing here
 * changes what is stored or billed.
 */
class AccountPreferencesController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user('web');
        abort_unless($user instanceof User, 403);

        $validated = $request->validate([
            'duration_style' => ['required', 'string', Rule::enum(DurationStyle::class)],
        ]);

        $user->update(['duration_style' => $validated['duration_style']]);

        return back();
    }
}
