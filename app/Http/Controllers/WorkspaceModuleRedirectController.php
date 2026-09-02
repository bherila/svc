<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Keeps a workspace-wide URL working after its screen became a client module.
 *
 * The sheets that spanned every client are gone: the operator works inside one
 * client, and the module tabs hang off that client. Their URLs are still in
 * bookmarks and in old links, so they resolve a client the way every other
 * entry into a workspace does - the one last opened, or the only one reachable
 * - rather than picking one here. The reader lands on the module they asked
 * for, for a client they chose.
 *
 * A controller rather than `Route::redirect` so the module travels as a route
 * default and the intent is readable at the route table.
 */
class WorkspaceModuleRedirectController extends Controller
{
    public function __invoke(Request $request, Workspace $workspace): RedirectResponse
    {
        $module = $request->route('module');

        return redirect()->route('workspaces.enter', [
            'workspace' => $workspace,
            ...is_string($module) ? ['module' => $module] : [],
        ]);
    }
}
