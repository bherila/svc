<?php

namespace App\Services\Search;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\BillingRecordAccess;
use App\Services\Authorization\ProjectAccess;
use App\Support\Search\SearchResult;
use App\Support\Search\SearchResultKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

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
    /** Rows per kind per workspace, so no one kind crowds the others out. */
    private const PER_KIND = 5;

    public function __construct(
        private readonly ProjectAccess $access,
        private readonly BillingRecordAccess $records,
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

        foreach ($this->workspacesFor($user) as $workspace) {
            foreach ($this->searchOneWorkspace($user, $workspace, $term) as $result) {
                $results[] = $result;
            }
        }

        // Grouped by kind in the palette's own order, so a client outranks a
        // task whichever workspace produced it.
        usort($results, fn (SearchResult $a, SearchResult $b): int => [$a->kind->rank(), $a->title] <=> [$b->kind->rank(), $b->title]);

        return $results;
    }

    /** @return Collection<int, Workspace> */
    private function workspacesFor(User $user): Collection
    {
        /** @var Collection<int, Workspace> $workspaces */
        $workspaces = Workspace::query()
            ->whereHas('memberships', fn (Builder $query) => $query->where('user_id', $user->id))
            ->orderBy('name')
            ->get();

        return $workspaces;
    }

    /** @return list<SearchResult> */
    private function searchOneWorkspace(User $user, Workspace $workspace, string $term): array
    {
        // Null means a manager, who reaches everything here including a client
        // with no projects at all. An empty list means a member added to
        // nothing, who reaches none of it.
        $viewableProjectIds = $this->access->viewableProjectIds($user, $workspace);
        $reachableCompanyIds = $this->access->reachableCompanyIds($user, $workspace);

        if ($viewableProjectIds === []) {
            return [];
        }

        $companies = ClientCompany::query()
            ->where('workspace_id', $workspace->id)
            ->when($reachableCompanyIds !== null, fn (Builder $q) => $q->whereIn('id', $reachableCompanyIds ?? []))
            ->tap(fn (Builder $q) => $this->whereContains($q, SearchColumn::Name, $term))
            ->orderBy('name')->limit(self::PER_KIND)->get();

        $projects = ClientProject::query()
            ->where('workspace_id', $workspace->id)
            ->when($viewableProjectIds !== null, fn (Builder $q) => $q->whereIn('id', $viewableProjectIds ?? []))
            ->tap(fn (Builder $q) => $this->whereContains($q, SearchColumn::Name, $term))
            ->orderBy('name')->limit(self::PER_KIND)->get();

        // The invoice screens' own rule, not company reachability. Reaching a
        // client through one project does not entitle someone to that client's
        // company-wide or other-project invoices, and `constrainInvoices` is
        // where that distinction is already drawn.
        $invoices = $this->records->constrainInvoices(
            ClientInvoice::query()->where('workspace_id', $workspace->id),
            $user,
            $workspace,
        )
            ->tap(fn (Builder $q) => $this->whereContains($q, SearchColumn::InvoiceNumber, $term))
            ->orderByDesc('issue_date')->limit(self::PER_KIND)->get();

        $tasks = ClientTask::query()
            ->where('workspace_id', $workspace->id)
            ->when($viewableProjectIds !== null, fn (Builder $q) => $q->whereIn('client_project_id', $viewableProjectIds ?? []))
            ->with('project')
            ->tap(fn (Builder $q) => $this->whereContains($q, SearchColumn::Title, $term))
            ->orderByDesc('updated_at')->limit(self::PER_KIND)->get();

        $parents = $this->parentCompanies($workspace, $companies, $projects, $invoices, $tasks);
        $base = function (mixed $companyId) use ($workspace, $parents): ?string {
            $id = (int) $companyId;

            return isset($parents[$id])
                ? '/workspaces/'.$workspace->public_id.'/clients/'.$parents[$id]->public_id
                : null;
        };

        $results = [];

        foreach ($companies as $company) {
            $href = $base($company->id);

            if ($href !== null) {
                $results[] = new SearchResult(SearchResultKind::Client, $company->public_id, $company->name, null, $href, $workspace->name);
            }
        }

        foreach ($projects as $project) {
            $href = $base($project->client_company_id);

            if ($href !== null) {
                $results[] = new SearchResult(SearchResultKind::Project, $project->public_id, $project->name,
                    $parents[(int) $project->client_company_id]->name, $href.'/projects/'.$project->public_id, $workspace->name);
            }
        }

        foreach ($invoices as $invoice) {
            $href = $base($invoice->client_company_id);

            if ($href !== null) {
                $results[] = new SearchResult(SearchResultKind::Invoice, $invoice->public_id, $invoice->invoice_number,
                    $parents[(int) $invoice->client_company_id]->name, $href.'/invoices/'.$invoice->public_id, $workspace->name);
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
                    $href.'/tasks?project='.$project->public_id, $workspace->name);
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
     * A substring match with the wildcards the caller typed neutralised.
     *
     * `%` and `_` are wildcards in LIKE, so a search for "50%" without this
     * matches everything beginning "50" - and a search for a single "%" would
     * return the whole table, which is exactly the query the empty-term guard
     * above exists to prevent. {@see SearchColumn} carries the SQL, and why it
     * has to name its escape character.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function whereContains(Builder $query, SearchColumn $column, string $term): void
    {
        $query->whereRaw($column->likeSql(), ['%'.self::escapeForLike($term).'%']);
    }

    /**
     * Neutralises the LIKE wildcards, using the escape character
     * {@see SearchColumn::likeSql()} declares.
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
