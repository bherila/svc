<?php

namespace App\Http\Middleware;

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\ProjectAccess;
use BWH\Auth\OAuth\ProviderApplications;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user('web');

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user === null ? null : [
                    'id' => $user->public_id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                    'created_at' => $user->created_at?->toIso8601String(),
                    'updated_at' => $user->updated_at?->toIso8601String(),
                ],
            ],
            // The sibling applications the identity provider reported for this person when
            // they signed in. Shared from the session rather than compiled into the bundle,
            // so which applications exist is visible only to someone actually signed in —
            // and the provider, not this application, decides what is listed.
            'applications' => $user === null
                ? []
                : ProviderApplications::forRequest($request),
            // The client the operator is currently working inside, and the ones
            // they could switch to. Shared rather than passed per page because
            // it is chrome: the switcher and the tab strip are the same on every
            // client screen, and a page that had to supply them would be free to
            // supply a different set.
            'clientContext' => $this->clientContext($request),
        ];
    }

    /**
     * The companies this viewer may switch between.
     *
     * Reachability is defined through projects, so this asks the same
     * authorization service the directory does rather than repeating the rule -
     * two copies of "which clients can they see" is how the directory and the
     * time sheet came to disagree in the first place.
     *
     * @return Collection<int, ClientCompany>
     */
    private function switchableCompanies(Workspace $workspace, User $user): Collection
    {
        $companies = $workspace->clientCompanies()->orderBy('name')->get();
        $viewable = app(ProjectAccess::class)->viewableProjectIds($user, $workspace);

        if ($viewable === null) {
            return $companies;
        }

        if ($viewable === []) {
            return new Collection;
        }

        $reachable = ClientProject::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $viewable)
            ->pluck('client_company_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->all();

        return $companies->filter(
            fn (ClientCompany $company): bool => in_array((int) $company->id, $reachable, true),
        )->values();
    }

    /**
     * The company switcher's options, plus which one is selected.
     *
     * Null off a workspace route - the portal has no switcher, and neither does
     * the dashboard - so this costs one query on client screens and nothing
     * anywhere else.
     *
     * Scoped through the workspace relation and gated by the same `view` policy
     * the directory uses. A switcher is a list of names, which is exactly the
     * kind of payload that leaks a client list across tenants if it is built
     * from anything looser than the workspace that owns them.
     *
     * @return array{
     *     workspace: array{id: string, name: string},
     *     companies: list<array{id: string, name: string}>,
     *     current_company_id: string|null,
     * }|null
     */
    private function clientContext(Request $request): ?array
    {
        // Client screens only. Operations and the dashboard are workspace
        // routes too, but they render no switcher - and a query paid on every
        // one of them is the kind of quiet growth
        // `WorkspaceOperationsTest::test_operations_queries_are_bounded_...`
        // exists to refuse. Company tabs are named `clients.*` as they land, so
        // they inherit this without another edit here.
        if (! str_starts_with((string) $request->route()?->getName(), 'clients.')) {
            return null;
        }

        $workspace = $request->route('workspace');
        $user = $request->user('web');

        if (! $workspace instanceof Workspace || $user === null) {
            return null;
        }

        if (! Gate::forUser($user)->allows('view', $workspace)) {
            return null;
        }

        $company = $request->route('clientCompany');

        return [
            'workspace' => [
                'id' => (string) $workspace->public_id,
                'name' => (string) $workspace->name,
            ],
            // Narrowed to the clients this viewer reaches (#157). The switcher
            // is the one place a client list appears on *every* client screen,
            // so a workspace-wide query here would publish the whole list to a
            // scoped member no matter which page they opened - which is exactly
            // what the directory's own scoping test caught.
            //
            // `array_values` rather than the collection's own `values()`: both
            // renumber, but only this one is a list to the analyser, and the
            // shape above says list.
            'companies' => array_values($this->switchableCompanies($workspace, $user)
                ->map(fn (ClientCompany $option): array => [
                    'id' => (string) $option->public_id,
                    'name' => (string) $option->name,
                ])
                ->all()),
            'current_company_id' => $company instanceof ClientCompany
                ? (string) $company->public_id
                : null,
            // Whether the Manage tab appears at all. Shared here rather than
            // per page because the strip is chrome, and a tab that shows for
            // some pages and not others reads as a bug. It gates the link
            // only - the action authorizes independently, because a hidden
            // link is not an authorization check.
            'can_manage' => Gate::forUser($user)->allows('manage', $workspace),
        ];
    }
}
