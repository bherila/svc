<?php

namespace App\Services\Search;

use App\Models\ClientCompany;
use App\Models\Workspace;
use App\Support\Search\SearchResult;
use Illuminate\Database\Eloquent\Collection;

/**
 * The names and routes a batch of search results needs, resolved once.
 *
 * A result row spells out its parents - a task names its project and its
 * client, a project names its client - and every one of those is a lookup the
 * row itself cannot do without a query. Keyed by internal id because that is
 * what the matched rows carry as foreign keys; nothing here reaches a caller,
 * which only ever sees {@see SearchResult} and its public
 * ids.
 */
final class SearchContext
{
    /** @var array<int, Workspace> */
    private array $workspaces = [];

    /** @var array<int, ClientCompany> */
    private array $companies = [];

    /**
     * @param  Collection<int, Workspace>  $workspaces
     * @param  Collection<int, ClientCompany>  $companies
     */
    public function __construct(Collection $workspaces, Collection $companies)
    {
        foreach ($workspaces as $workspace) {
            $this->workspaces[(int) $workspace->id] = $workspace;
        }

        foreach ($companies as $company) {
            $this->companies[(int) $company->id] = $company;
        }
    }

    public function company(int $id): ?ClientCompany
    {
        return $this->companies[$id] ?? null;
    }

    /**
     * The client screen's route, which every other client-scoped href extends.
     *
     * One place builds it, because the palette's destinations have to stay the
     * same routes the tab strip links to. Two spellings of the same path is how
     * a shortcut quietly starts landing somewhere the navigation does not.
     *
     * Null when the company's workspace was not loaded. That should not happen
     * - companies are only ever matched inside workspaces this viewer belongs
     * to, and all of those are loaded - but the alternative to returning null
     * is interpolating an empty segment and handing back `/workspaces//clients/x`,
     * which routes nowhere and looks like a link. A row with no destination is
     * dropped by the caller instead.
     */
    public function companyHref(ClientCompany $company): ?string
    {
        $workspace = $this->workspaces[(int) $company->workspace_id] ?? null;

        if ($workspace === null) {
            return null;
        }

        return '/workspaces/'.$workspace->public_id.'/clients/'.$company->public_id;
    }

    public function workspaceName(int $id): string
    {
        return $this->workspaces[$id]->name ?? '';
    }
}
