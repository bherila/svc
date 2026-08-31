<?php

namespace App\Http\Middleware;

use App\Models\ClientCompany;
use App\Models\Workspace;
use BWH\Auth\OAuth\ProviderApplications;
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
            // `array_values` rather than the collection's own `values()`:
            // both renumber, but only this one is a list to the analyser, and
            // the shape above says list.
            'companies' => array_values($workspace->clientCompanies()
                ->orderBy('name')
                ->get()
                ->map(fn (ClientCompany $option): array => [
                    'id' => (string) $option->public_id,
                    'name' => (string) $option->name,
                ])
                ->all()),
            'current_company_id' => $company instanceof ClientCompany
                ? (string) $company->public_id
                : null,
        ];
    }
}
