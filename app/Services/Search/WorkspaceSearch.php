<?php

namespace App\Services\Search;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\ProjectAccess;
use App\Support\Search\SearchResult;
use App\Support\Search\SearchResultKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * What the command palette can find, for one person, across their workspaces.
 *
 * Reachability is not re-derived here. Which clients someone sees runs through
 * projects, and that rule already exists once in
 * {@see ProjectAccess} because three surfaces
 * needed it and two copies is how the directory and the time sheet came to
 * disagree. This service asks the same question in the same shape - manager of
 * a workspace, or a member of specific projects - so a palette hit can never
 * name a client the switcher would not offer.
 *
 * It is deliberately a LIKE scan. The database is local and small, there is no
 * search index to keep in step with writes, and an index that can go stale is
 * a worse failure than a slow query: it answers confidently with the wrong
 * set. When the row counts justify it, the replacement is a FULLTEXT index on
 * the same columns - the grouping and the authorization above it do not change.
 */
final class WorkspaceSearch
{
    /** Rows returned per kind, so no one kind can crowd the others out. */
    private const PER_KIND = 5;

    public function __construct(private readonly ProjectAccess $access) {}

    /**
     * Everything this person can reach that matches, grouped by kind.
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

        $workspaces = $this->workspacesFor($user);

        if ($workspaces->isEmpty()) {
            return [];
        }

        // Manager of a workspace reaches everything in it, including a client
        // with no projects at all - which nobody could otherwise reach.
        // Everyone else reaches exactly the projects they are a member of.
        $managedWorkspaceIds = $this->managedWorkspaceIds($user, $workspaces);
        $memberProjectIds = $this->memberProjectIds($user, $workspaces, $managedWorkspaceIds);

        if ($managedWorkspaceIds === [] && $memberProjectIds === []) {
            return [];
        }

        $projects = $this->matchingProjects($term, $managedWorkspaceIds, $memberProjectIds);
        // Companies reachable through a project the viewer is a member of.
        // Resolved from the *membership*, not from the matched projects, so a
        // client whose name matches is found even when none of its projects do.
        $reachableCompanyIds = $this->reachableCompanyIds($memberProjectIds);
        $companies = $this->matchingCompanies($term, $managedWorkspaceIds, $reachableCompanyIds);

        $context = $this->context($workspaces, $companies, $projects, $managedWorkspaceIds, $reachableCompanyIds);

        return [
            ...$this->clientResults($companies, $context),
            ...$this->projectResults($projects, $context),
            ...$this->invoiceResults($term, $managedWorkspaceIds, $reachableCompanyIds, $context),
            ...$this->taskResults($term, $managedWorkspaceIds, $memberProjectIds, $context),
        ];
    }

    /** @return Collection<int, Workspace> */
    private function workspacesFor(User $user): Collection
    {
        /** @var Collection<int, Workspace> $workspaces */
        $workspaces = Workspace::query()
            ->whereHas('memberships', fn (Builder $query) => $query->where('user_id', $user->id))
            ->get();

        return $workspaces;
    }

    /**
     * The workspaces this person manages, which are the ones they reach whole.
     *
     * The roles are the two `ProjectAccess::isWorkspaceManager()` names, and
     * they mean the same thing here: membership alone is not enough. A plain
     * member of a workspace reaches only the projects they were added to, so
     * treating every membership as managerial would publish the whole client
     * list to someone the directory scopes down - the exact defect #157 fixed
     * on the directory and the time sheet.
     *
     * @param  Collection<int, Workspace>  $workspaces
     * @return list<int>
     */
    private function managedWorkspaceIds(User $user, Collection $workspaces): array
    {
        $managed = [];

        foreach ($workspaces as $workspace) {
            if ($this->access->isWorkspaceManager($user, $workspace)) {
                $managed[] = (int) $workspace->id;
            }
        }

        return $managed;
    }

    /**
     * The projects this person is explicitly a member of, in workspaces they
     * do not manage.
     *
     * @param  Collection<int, Workspace>  $workspaces
     * @param  list<int>  $managedWorkspaceIds
     * @return list<int>
     */
    private function memberProjectIds(User $user, Collection $workspaces, array $managedWorkspaceIds): array
    {
        $unmanaged = array_values(array_diff(
            array_map(fn (mixed $id): int => (int) $id, $workspaces->pluck('id')->all()),
            $managedWorkspaceIds,
        ));

        if ($unmanaged === []) {
            return [];
        }

        return array_values(ClientProjectMembership::query()
            ->whereIn('workspace_id', $unmanaged)
            ->where('user_id', $user->id)
            ->pluck('client_project_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }

    /**
     * @param  list<int>  $memberProjectIds
     * @return list<int>
     */
    private function reachableCompanyIds(array $memberProjectIds): array
    {
        if ($memberProjectIds === []) {
            return [];
        }

        return array_values(array_unique(ClientProject::query()
            ->whereIn('id', $memberProjectIds)
            ->pluck('client_company_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all()));
    }

    /**
     * @param  list<int>  $managedWorkspaceIds
     * @param  list<int>  $memberProjectIds
     * @return Collection<int, ClientProject>
     */
    private function matchingProjects(string $term, array $managedWorkspaceIds, array $memberProjectIds): Collection
    {
        /** @var Collection<int, ClientProject> $projects */
        $projects = ClientProject::query()
            ->where(fn (Builder $query) => $query
                ->whereIn('workspace_id', $managedWorkspaceIds)
                ->orWhereIn('id', $memberProjectIds))
            ->tap(fn (Builder $query) => $this->whereContains($query, SearchColumn::Name, $term))
            ->orderBy('name')
            ->limit(self::PER_KIND)
            ->get();

        return $projects;
    }

    /**
     * @param  list<int>  $managedWorkspaceIds
     * @param  list<int>  $reachableCompanyIds
     * @return Collection<int, ClientCompany>
     */
    private function matchingCompanies(string $term, array $managedWorkspaceIds, array $reachableCompanyIds): Collection
    {
        /** @var Collection<int, ClientCompany> $companies */
        $companies = ClientCompany::query()
            ->where(fn (Builder $query) => $query
                ->whereIn('workspace_id', $managedWorkspaceIds)
                ->orWhereIn('id', $reachableCompanyIds))
            ->tap(fn (Builder $query) => $this->whereContains($query, SearchColumn::Name, $term))
            ->orderBy('name')
            ->limit(self::PER_KIND)
            ->get();

        return $companies;
    }

    /**
     * Names for the ids the result rows have to spell out.
     *
     * Loaded once for every kind rather than per row: a task names its project
     * and its client, and resolving those lazily is how a five-row palette
     * becomes twenty queries.
     *
     * @param  Collection<int, Workspace>  $workspaces
     * @param  Collection<int, ClientCompany>  $companies
     * @param  Collection<int, ClientProject>  $projects
     * @param  list<int>  $managedWorkspaceIds
     * @param  list<int>  $reachableCompanyIds
     */
    private function context(
        Collection $workspaces,
        Collection $companies,
        Collection $projects,
        array $managedWorkspaceIds,
        array $reachableCompanyIds,
    ): SearchContext {
        $companyIds = array_values(array_unique([
            ...$reachableCompanyIds,
            ...array_map(fn (mixed $id): int => (int) $id, $companies->pluck('id')->all()),
            ...array_map(fn (mixed $id): int => (int) $id, $projects->pluck('client_company_id')->all()),
        ]));

        // Every company in a managed workspace is a possible parent of a match,
        // so those are loaded too rather than only the ones already matched.
        /** @var Collection<int, ClientCompany> $parents */
        $parents = ClientCompany::query()
            ->where(fn (Builder $query) => $query
                ->whereIn('workspace_id', $managedWorkspaceIds)
                ->orWhereIn('id', $companyIds))
            ->get();

        return new SearchContext($workspaces, $parents);
    }

    /**
     * @param  Collection<int, ClientCompany>  $companies
     * @return list<SearchResult>
     */
    private function clientResults(Collection $companies, SearchContext $context): array
    {
        return array_values($companies->map(function (ClientCompany $company) use ($context): ?SearchResult {
            $href = $context->companyHref($company);

            if ($href === null) {
                return null;
            }

            return new SearchResult(
                kind: SearchResultKind::Client,
                id: $company->public_id,
                title: $company->name,
                subtitle: null,
                href: $href,
                workspaceName: $context->workspaceName($company->workspace_id),
            );
        })->filter()->all());
    }

    /**
     * @param  Collection<int, ClientProject>  $projects
     * @return list<SearchResult>
     */
    private function projectResults(Collection $projects, SearchContext $context): array
    {
        return array_values($projects->map(function (ClientProject $project) use ($context): ?SearchResult {
            $company = $context->company($project->client_company_id);
            $href = $company === null ? null : $context->companyHref($company);

            // A project whose client is not loaded has nowhere to link to -
            // the route is client-scoped - so it is dropped rather than
            // linked at a guessed path.
            if ($company === null || $href === null) {
                return null;
            }

            return new SearchResult(
                kind: SearchResultKind::Project,
                id: $project->public_id,
                title: $project->name,
                subtitle: $company->name,
                href: $href.'/projects/'.$project->public_id,
                workspaceName: $context->workspaceName($project->workspace_id),
            );
        })->filter()->all());
    }

    /**
     * @param  list<int>  $managedWorkspaceIds
     * @param  list<int>  $reachableCompanyIds
     * @return list<SearchResult>
     */
    private function invoiceResults(string $term, array $managedWorkspaceIds, array $reachableCompanyIds, SearchContext $context): array
    {
        /** @var Collection<int, ClientInvoice> $invoices */
        $invoices = ClientInvoice::query()
            ->where(fn (Builder $query) => $query
                ->whereIn('workspace_id', $managedWorkspaceIds)
                ->orWhereIn('client_company_id', $reachableCompanyIds))
            ->tap(fn (Builder $query) => $this->whereContains($query, SearchColumn::InvoiceNumber, $term))
            ->orderByDesc('issue_date')
            ->limit(self::PER_KIND)
            ->get();

        return array_values($invoices->map(function (ClientInvoice $invoice) use ($context): ?SearchResult {
            $company = $context->company($invoice->client_company_id);
            $href = $company === null ? null : $context->companyHref($company);

            if ($company === null || $href === null) {
                return null;
            }

            return new SearchResult(
                kind: SearchResultKind::Invoice,
                id: $invoice->public_id,
                title: $invoice->invoice_number,
                subtitle: $company->name,
                href: $href.'/invoices/'.$invoice->public_id,
                workspaceName: $context->workspaceName($invoice->workspace_id),
            );
        })->filter()->all());
    }

    /**
     * Tasks resolve to their client's Tasks tab, which is the screen that
     * exists. There is no per-task route, and inventing one here would be a
     * link to a 404 rather than a shortcut.
     *
     * @param  list<int>  $managedWorkspaceIds
     * @param  list<int>  $memberProjectIds
     * @return list<SearchResult>
     */
    private function taskResults(string $term, array $managedWorkspaceIds, array $memberProjectIds, SearchContext $context): array
    {
        /** @var Collection<int, ClientTask> $tasks */
        $tasks = ClientTask::query()
            ->with('project')
            ->where(fn (Builder $query) => $query
                ->whereIn('workspace_id', $managedWorkspaceIds)
                ->orWhereIn('client_project_id', $memberProjectIds))
            ->tap(fn (Builder $query) => $this->whereContains($query, SearchColumn::Title, $term))
            ->orderByDesc('updated_at')
            ->limit(self::PER_KIND)
            ->get();

        return array_values($tasks->map(function (ClientTask $task) use ($context): ?SearchResult {
            $project = $task->project;
            $company = $context->company($project->client_company_id);
            $href = $company === null ? null : $context->companyHref($company);

            if ($company === null || $href === null) {
                return null;
            }

            return new SearchResult(
                kind: SearchResultKind::Task,
                id: $task->public_id,
                title: $task->title,
                subtitle: $project->name.' · '.$company->name,
                href: $href.'/tasks',
                workspaceName: $context->workspaceName($task->workspace_id),
            );
        })->filter()->all());
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
