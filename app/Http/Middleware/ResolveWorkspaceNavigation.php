<?php

namespace App\Http\Middleware;

use App\Http\Controllers\WorkspaceEntryController;
use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Navigation\WorkspaceNavigationFactory;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attaches the workspace chrome to the pages that render it.
 *
 * Route middleware rather than a branch inside `HandleInertiaRequests`, because
 * the question "does this screen have a navbar" is a property of the route, and
 * expressing it as a string test on the route's name made the answer depend on
 * a naming convention nobody is reminded of. It also decides where the cost
 * lands: the switcher's queries are paid on the screens that show a switcher
 * and on no others - not on the workspace selector, the login redirect, a
 * webhook, a PDF or a download.
 *
 * Shared as `workspaceNavigation` and never merged into the page's own props,
 * so a page cannot supply a switcher of its own devising.
 */
class ResolveWorkspaceNavigation
{
    public function __construct(private readonly WorkspaceNavigationFactory $factory) {}

    public function handle(Request $request, Closure $next): Response
    {
        $workspace = $this->workspace($request);
        $user = $request->user('web');

        if ($workspace instanceof Workspace && $user instanceof User) {
            $company = $request->route('clientCompany');

            $navigation = $this->factory->for(
                $workspace,
                $user,
                // Bound from the route, and only when it belongs to this
                // workspace. A company id is unique across every tenant, so a
                // pasted one arrives bound and plausible; the factory refuses
                // to mark it current, but only if it is never handed one from
                // somewhere else.
                $company instanceof ClientCompany && (int) $company->workspace_id === (int) $workspace->id
                    ? $company
                    : null,
            );

            // Written here rather than by each controller, and only from the
            // id the factory was willing to call current - which is to say only
            // after authorization. That is what lets the workspace entry point
            // route on it later without asking the browser to be trusted.
            if ($navigation->currentClientId !== null) {
                $request->session()->put(
                    WorkspaceEntryController::rememberedClientKey($workspace),
                    $navigation->currentClientId,
                );
            }

            Inertia::share('workspaceNavigation', $navigation->toArray());
        }

        return $next($request);
    }

    /**
     * The workspace this request is inside.
     *
     * Named in the route on operator screens, and derived from the company on
     * portal ones - a portal URL carries no workspace segment because its
     * viewer is not a member of one, but the chrome above it still belongs to
     * exactly one tenant.
     */
    private function workspace(Request $request): ?Workspace
    {
        $workspace = $request->route('workspace');

        if ($workspace instanceof Workspace) {
            return $workspace;
        }

        $company = $request->route('clientCompany');

        return $company instanceof ClientCompany ? $company->workspace : null;
    }
}
