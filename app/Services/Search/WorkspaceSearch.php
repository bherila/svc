<?php

namespace App\Services\Search;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\HistoricalEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\BillingRecordAccess;
use App\Services\Authorization\PortalAccess;
use App\Services\Authorization\PortalInvoiceQuery;
use App\Services\Authorization\ProjectAccess;
use App\Services\Collaboration\CollaborationAudience;
use App\Services\History\HistorySearch;
use App\Services\Tasks\TaskVisibility;
use App\Support\Search\SearchResult;
use App\Support\Search\SearchResultKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * What the command palette can find, for one person, across their workspaces.
 *
 * ## One workspace at a time, on purpose
 *
 * Every query here is scoped to a single workspace, and the workspaces are
 * walked in turn. An earlier version searched them all at once with clauses of
 * the form "in a workspace I manage, OR naming a project I belong to" - which
 * reads as scoped and is not: the second half never constrains the row's own
 * `workspace_id`, so a malformed-lineage row from another tenant naming a
 * project id this viewer holds would have matched. Per-workspace scoping makes
 * that unrepresentable rather than guarded against.
 *
 * ## Authorization is borrowed, never restated
 *
 * Which clients someone sees runs through projects, and which invoices they may
 * open is narrower still - an invoice needs lineage inside what they hold, and
 * one with no lineage at all is refused. Those rules live in
 * {@see ProjectAccess} and {@see BillingRecordAccess}, which the directory, the
 * switcher and the invoice screens already use. This asks them rather than
 * reproducing them, because a result the reader is then refused is not a small
 * bug: it discloses that the record exists and what it is called.
 *
 * ## Matching
 *
 * A LIKE scan, deliberately. The database is local and small, there is no
 * search index to keep in step with writes, and an index that can go stale
 * answers confidently with the wrong set. When the row counts justify it the
 * replacement is a FULLTEXT index on the same columns; the grouping and the
 * authorization above it do not change.
 */
final class WorkspaceSearch
{
    public function __construct(
        private readonly ProjectAccess $access,
        private readonly BillingRecordAccess $records,
        private readonly HistorySearch $history,
        private readonly CollaborationSearch $collaboration,
        private readonly PortalAccess $portal,
        private readonly PortalInvoiceQuery $portalInvoices,
        private readonly TaskVisibility $taskVisibility,
        private readonly PaletteRecordSearch $paletteRecords,
        private readonly PaletteAudiences $audiences,
    ) {}

    /**
     * Everything this person can reach that matches.
     *
     * An empty or whitespace-only term returns nothing rather than everything:
     * the palette opens empty, and a blank search that dumped the workspace
     * would be both useless and the most expensive query on the page.
     *
     * @return list<SearchResult>
     */
    public function forUser(User $user, string $term): array
    {
        $term = trim($term);

        if ($term === '') {
            return [];
        }

        $results = [];

        foreach ($this->audiences->for($user) as [$workspace, $audience]) {
            foreach ($this->searchOneWorkspace($user, $workspace, SearchLookup::term($term), [], $audience) as $result) {
                $results[] = $result;
            }
        }

        // Grouped by kind in the palette's own order, so a client outranks a
        // task whichever workspace produced it.
        usort($results, fn (SearchResult $a, SearchResult $b): int => [$a->kind->rank(), $a->kind->listedByTitle() ? $a->title : ''] <=> [$b->kind->rank(), $b->kind->listedByTitle() ? $b->title : '']);

        return $results;
    }

    /**
     * The viewer's recent items, resolved again through the authorized queries.
     *
     * @param  array<string, array<string, list<string>>>  $recents  Public ids by kind value, by workspace public id.
     * @return list<SearchResult>
     */
    public function forRecents(User $user, array $recents): array
    {
        $results = [];

        foreach ($this->audiences->for($user) as [$workspace, $audience]) {
            $ids = $recents[$workspace->public_id] ?? [];
            if ($ids === []) {
                continue;
            }
            $kinds = array_values(array_filter(SearchResultKind::recentable(), fn (SearchResultKind $kind): bool => ($ids[$kind->value] ?? []) !== []));
            foreach ($this->searchOneWorkspace($user, $workspace, SearchLookup::ids($ids), $kinds, $audience) as $result) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /** @param list<SearchResultKind> $kinds
     * @return list<SearchResult> */
    public function forWorkspace(User $user, Workspace $workspace, string $term, array $kinds, CollaborationAudience $audience): array
    {
        if (trim($term) === '' || $kinds === []) {
            return [];
        }
        $results = $this->searchOneWorkspace($user, $workspace, SearchLookup::term(trim($term)), $kinds, $audience);
        usort($results, fn (SearchResult $a, SearchResult $b): int => [$a->kind->rank(), $a->kind->listedByTitle() ? $a->title : ''] <=> [$b->kind->rank(), $b->kind->listedByTitle() ? $b->title : '']);

        return $results;
    }

    /** @param list<SearchResultKind> $kinds Empty means all kinds for the web palette.
     * @return list<SearchResult> */
    private function searchOneWorkspace(User $user, Workspace $workspace, SearchLookup $lookup, array $kinds, CollaborationAudience $audience): array
    {
        // Null means a manager, who reaches everything here including a client
        // with no projects at all. An empty list means a member added to
        // nothing, who reaches none of it.
        $isPortal = $audience === CollaborationAudience::Portal;
        $viewableProjectIds = $isPortal
            ? $this->portal->constrainProjectQuery(ClientProject::query()->where('workspace_id', $workspace->id)->where('is_visible_to_client', true), $user)->pluck('id')->all()
            : $this->access->viewableProjectIds($user, $workspace);
        $reachableCompanyIds = $isPortal
            ? ClientCompanyMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $user->id)->pluck('client_company_id')->all()
            : $this->access->reachableCompanyIds($user, $workspace);

        if ($viewableProjectIds === [] && (! $isPortal || $reachableCompanyIds === [])) {
            return [];
        }

        // A kind that is not asked for (or, for recents, has no ids) is never
        // queried at all rather than queried and emptied.
        $wants = static fn (SearchResultKind $kind): bool => ($kinds === [] || in_array($kind, $kinds, true)) && $lookup->wants($kind);

        $companies = ! $wants(SearchResultKind::Client) ? new Collection : ClientCompany::query()
            ->where('workspace_id', $workspace->id)
            ->when($reachableCompanyIds !== null, fn (Builder $q) => $q->whereIn('id', $reachableCompanyIds ?? []))
            ->tap(fn (Builder $q) => $lookup->constrain($q, SearchColumn::Name, SearchResultKind::Client))
            ->orderBy('name')->limit($lookup->limit(SearchResultKind::Client))->get();

        $projects = ! $wants(SearchResultKind::Project) ? new Collection : ClientProject::query()
            ->whereHas('clientCompany', fn (Builder $company) => $company->where('workspace_id', $workspace->id))
            ->where('workspace_id', $workspace->id)
            ->when($viewableProjectIds !== null, fn (Builder $q) => $q->whereIn('id', $viewableProjectIds ?? []))
            ->tap(fn (Builder $q) => $lookup->constrain($q, SearchColumn::Name, SearchResultKind::Project))
            ->orderBy('name')->limit($lookup->limit(SearchResultKind::Project))->get();

        // The invoice screens' own rule, not company reachability. Reaching a
        // client through one project does not entitle someone to that client's
        // company-wide or other-project invoices, and `constrainInvoices` is
        // where that distinction is already drawn.
        $invoiceQuery = $isPortal
            ? $this->portalInvoices->visibleInWorkspace($workspace, $user)
            : $this->records->constrainInvoices(ClientInvoice::query()->where('workspace_id', $workspace->id), $user, $workspace);
        $invoices = ! $wants(SearchResultKind::Invoice) ? new Collection : $invoiceQuery
            ->tap(fn (Builder $q) => $lookup->constrain($q, SearchColumn::InvoiceNumber, SearchResultKind::Invoice))
            ->orderByDesc('issue_date')->limit($lookup->limit(SearchResultKind::Invoice))->get();

        $tasks = ! $wants(SearchResultKind::Task) ? new Collection : ClientTask::query()
            ->when($isPortal, fn (Builder $query) => $this->taskVisibility->constrainPortal($query, $workspace, $user))
            ->where('workspace_id', $workspace->id)
            ->when($viewableProjectIds !== null, fn (Builder $q) => $q->whereIn('client_project_id', $viewableProjectIds ?? []))
            ->whereHas('project', fn (Builder $q) => $q->where('workspace_id', $workspace->id)
                ->whereHas('clientCompany', fn (Builder $company) => $company->where('workspace_id', $workspace->id)))
            ->with(['project' => fn ($q) => $q->where('workspace_id', $workspace->id)])
            ->tap(fn (Builder $q) => $lookup->constrain($q, SearchColumn::Title, SearchResultKind::Task))
            ->orderByDesc('updated_at')->limit($lookup->limit(SearchResultKind::Task))->get();

        $parents = $this->parentCompanies($workspace, $companies, $projects, $invoices, $tasks);
        $base = function (mixed $companyId) use ($workspace, $parents, $isPortal): ?string {
            $id = (int) $companyId;

            return isset($parents[$id])
                ? ($isPortal ? '/portal/'.$parents[$id]->public_id : '/workspaces/'.$workspace->public_id.'/clients/'.$parents[$id]->public_id)
                : null;
        };

        $results = [];

        foreach ($companies as $company) {
            $href = $base($company->id);

            if ($href !== null) {
                $results[] = new SearchResult(SearchResultKind::Client, $company->public_id, $company->name, null, $href, $workspace->name, $workspace->public_id);
            }
        }

        foreach ($projects as $project) {
            $href = $base($project->client_company_id);

            if ($href !== null) {
                $results[] = new SearchResult(SearchResultKind::Project, $project->public_id, $project->name,
                    $parents[(int) $project->client_company_id]->name, $isPortal ? $href.'/tasks?project='.$project->public_id : $href.'/projects/'.$project->public_id, $workspace->name, $workspace->public_id);
            }
        }

        foreach ($invoices as $invoice) {
            $href = $base($invoice->client_company_id);

            if ($href !== null) {
                $results[] = new SearchResult(SearchResultKind::Invoice, $invoice->public_id, $invoice->invoice_number,
                    $parents[(int) $invoice->client_company_id]->name, $href.'/invoices/'.$invoice->public_id, $workspace->name, $workspace->public_id);
            }
        }

        // A task has no screen of its own, so it resolves to its client's Tasks
        // tab, filtered to the project it belongs to. Inventing a per-task
        // route here would be a link to a 404; landing on the unfiltered list
        // would leave the reader to find the row they searched for.
        foreach ($tasks as $task) {
            $project = $task->project;
            $href = $base($project->client_company_id);

            if ($href !== null) {
                $results[] = new SearchResult(SearchResultKind::Task, $task->public_id, $task->title,
                    $project->name.' - '.$parents[(int) $project->client_company_id]->name,
                    $href.'/tasks?project='.$project->public_id, $workspace->name, $workspace->public_id);
            }
        }

        $results = [...$results, ...$this->paletteRecords->forWorkspace($user, $workspace, $lookup, $kinds, $isPortal, $viewableProjectIds, $reachableCompanyIds)];

        // Notes, comments and history are found by a typed term only; they are
        // not among the kinds a recent item can name.
        if ($lookup->term === null) {
            return $results;
        }

        $term = $lookup->term;
        $results = [...$results, ...$this->collaboration->forWorkspace($user, $workspace, $term, $kinds, $audience)];

        // Source grants are preserved as history, never activated as current
        // project/portal access. Only workspace managers can search this library.
        if (($kinds === [] || in_array(SearchResultKind::History, $kinds, true)) && Gate::forUser($user)->allows('manage', $workspace)) {
            $history = HistoricalEntry::query()->where('workspace_id', $workspace->id);
            $this->history->matching($history, $term);
            foreach ($history->orderByDesc('id')->limit(SearchLookup::PER_KIND)->get() as $entry) {
                $results[] = new SearchResult(SearchResultKind::History, $entry->public_id, $entry->title,
                    $entry->kind->label(), route('history.show', [$workspace, $entry], absolute: false), $workspace->name);
            }
        }

        return $results;
    }

    /**
     * The companies the matched rows actually name, and only those.
     *
     * Bounded by the results rather than by the workspace. Loading every client
     * a manager can see would make each debounced keystroke scale with their
     * whole client population however few rows the per-kind limits let through
     * - which is the unbounded eager load AGENTS.md asks to be avoided, on the
     * one surface that runs on every keypress.
     *
     * @param  Collection<int, ClientCompany>  $companies
     * @param  Collection<int, ClientProject>  $projects
     * @param  Collection<int, ClientInvoice>  $invoices
     * @param  Collection<int, ClientTask>  $tasks
     * @return array<int, ClientCompany>
     */
    private function parentCompanies(Workspace $workspace, Collection $companies, Collection $projects, Collection $invoices, Collection $tasks): array
    {
        $ids = array_values(array_unique(array_map(fn (mixed $id): int => (int) $id, [
            ...$companies->pluck('id')->all(),
            ...$projects->pluck('client_company_id')->all(),
            ...$invoices->pluck('client_company_id')->all(),
            ...$tasks->map(fn (ClientTask $task): int => (int) $task->project->client_company_id)->all(),
        ])));

        if ($ids === []) {
            return [];
        }

        $parents = [];

        foreach (ClientCompany::query()->where('workspace_id', $workspace->id)->whereIn('id', $ids)->get() as $company) {
            $parents[(int) $company->id] = $company;
        }

        return $parents;
    }

    /**
     * Neutralises the LIKE wildcards, using the escape character
     * {@see SearchColumn::likeSql()} declares. {@see SearchLookup::constrain()}
     * is where a typed term meets the SQL, and `%` and `_` here are what stop
     * a bare `%` meaning "everything" - the query the empty-term guard exists
     * to prevent.
     *
     * `addcslashes` is deliberately not used: it emits backslashes, and a
     * backslash cannot be this query's escape character without breaking
     * MariaDB's string literal. The escape character is escaped first so a
     * caller typing `!` gets a literal `!` rather than a dangling escape that
     * swallows the character after it.
     *
     * Public so a test can pin it against the SQL that consumes it; nothing
     * else calls it.
     */
    public static function escapeForLike(string $term): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
    }
}
