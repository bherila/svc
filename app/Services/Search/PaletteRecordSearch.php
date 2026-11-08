<?php

namespace App\Services\Search;

use App\Models\ClientAgreement;
use App\Models\ClientAttachment;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientContact;
use App\Models\ClientExpense;
use App\Models\ClientProject;
use App\Models\ClientProposal;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentTimeEntryQuery;
use App\Services\Authorization\BillingRecordAccess;
use App\Services\Authorization\PortalAccess;
use App\Services\Files\AttachmentAudience;
use App\Services\Files\AttachmentReader;
use App\Services\Files\AttachmentRecordResolver;
use App\Support\Search\SearchResult;
use App\Support\Search\SearchResultKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The palette's record kinds that are not one of the original four: expenses,
 * agreements, proposals, files, time entries, contacts and portal users.
 *
 * ## Each kind asks the page it links to
 *
 * Every builder below is the rule of the screen the row opens, not a restatement
 * of "can see the workspace". Expenses follow the client Expenses tab (reachable
 * company and a project the viewer holds), agreements follow
 * {@see BillingRecordAccess::constrainAgreements()}, files follow
 * {@see AttachmentReader}, time follows {@see AgentTimeEntryQuery} - the time
 * sheet's own query - and the portal kinds follow the portal controller's
 * conditions (client-visible, not a draft, approved with a client-safe
 * description). A row the reader is then refused on arrival would disclose that
 * the record exists and what it is called.
 *
 * ## Bounded
 *
 * One query per kind, one for the clients the rows name, and a fixed handful to
 * work out which client a file's parent record belongs to. None of it grows with
 * the number of rows matched, which `SearchTest` pins.
 */
final class PaletteRecordSearch
{
    /** How many of the newest readable files a filename search looks through. */
    private const FILE_WINDOW = 300;

    public function __construct(
        private readonly BillingRecordAccess $billing,
        private readonly PortalAccess $portal,
        private readonly AttachmentReader $files,
        private readonly AgentTimeEntryQuery $time,
    ) {}

    /**
     * @param  list<SearchResultKind>  $kinds  Empty means every kind.
     * @param  list<int>|null  $viewable  Projects the viewer holds; null for an owner or admin.
     * @param  list<int>|null  $reachable  Companies the viewer reaches; null for an owner or admin.
     * @return list<SearchResult>
     */
    public function forWorkspace(User $user, Workspace $workspace, SearchLookup $lookup, array $kinds, bool $isPortal, ?array $viewable, ?array $reachable): array
    {
        $wants = static fn (SearchResultKind $kind): bool => ($kinds === [] || in_array($kind, $kinds, true)) && $lookup->wants($kind);
        $manages = ! $isPortal && $viewable === null;
        $companyIds = fn (): Builder => ClientCompany::query()->where('workspace_id', $workspace->id)
            ->when($reachable !== null, fn (Builder $q) => $q->whereIn('id', $reachable ?? []))->select('id');

        /** @var list<array{SearchResultKind, Collection<int, covariant \Illuminate\Database\Eloquent\Model>}> $found */
        $found = [];

        if ($wants(SearchResultKind::Expense) && ! $isPortal) {
            $found[] = [SearchResultKind::Expense, $this->expenses($workspace, $lookup, $companyIds(), $viewable)];
        }
        if ($wants(SearchResultKind::Agreement)) {
            $found[] = [SearchResultKind::Agreement, $this->agreements($user, $workspace, $lookup, $companyIds(), $isPortal)];
        }
        if ($wants(SearchResultKind::Proposal)) {
            $found[] = [SearchResultKind::Proposal, $this->proposals($user, $workspace, $lookup, $companyIds(), $isPortal, $viewable)];
        }
        if ($wants(SearchResultKind::Time)) {
            $found[] = [SearchResultKind::Time, $this->timeEntries($user, $workspace, $lookup, $companyIds(), $isPortal, $viewable)];
        }
        if ($wants(SearchResultKind::Contact) && $manages) {
            $found[] = [SearchResultKind::Contact, $this->contacts($workspace, $lookup, $companyIds())];
        }
        if ($wants(SearchResultKind::PortalUser) && $manages) {
            $found[] = [SearchResultKind::PortalUser, $this->memberships($workspace, $lookup, $companyIds())];
        }

        $files = $wants(SearchResultKind::File) ? $this->attachments($user, $workspace, $lookup, $isPortal) : null;

        $needed = [];
        foreach ($found as [$kind, $rows]) {
            foreach ($rows as $row) {
                $needed[] = (int) $row->getAttribute('client_company_id');
            }
        }
        $fileParents = $files === null ? [] : $this->fileParents($workspace, $files);
        foreach ($fileParents as $companyId) {
            $needed[] = $companyId;
        }
        $needed = array_values(array_unique($needed));
        /** @var Collection<int, ClientCompany> $companies */
        $companies = $needed === [] ? new Collection : ClientCompany::query()->where('workspace_id', $workspace->id)->whereIn('id', $needed)->get()->keyBy('id');

        $results = [];
        $make = fn (SearchResultKind $kind, string $id, string $title, ?string $subtitle, string $href): SearchResult => new SearchResult(
            $kind, $id, $title, $subtitle, $href, (string) $workspace->name, (string) $workspace->public_id,
        );

        foreach ($found as [$kind, $rows]) {
            if ($kind === SearchResultKind::Time) {
                $this->loadProjects($workspace, $rows);
            }
            foreach ($rows as $row) {
                $company = $companies->get((int) $row->getAttribute('client_company_id'));
                if (! $company instanceof ClientCompany) {
                    continue;
                }
                $result = match ($kind) {
                    SearchResultKind::Expense => $row instanceof ClientExpense ? $make($kind, $row->public_id, Str::limit((string) $row->description, 120),
                        $row->spent_on->toDateString().' - '.$company->name, route('clients.expenses', [$workspace, $company], absolute: false)) : null,
                    SearchResultKind::Agreement => $row instanceof ClientAgreement ? $make($kind, $row->public_id, (string) $row->title, $company->name,
                        $isPortal ? route('portal.agreement', [$company, $row], absolute: false) : route('clients.agreement', [$workspace, $company, $row], absolute: false)) : null,
                    SearchResultKind::Proposal => $row instanceof ClientProposal ? $make($kind, $row->public_id, (string) $row->title, $company->name,
                        $isPortal ? route('portal.proposal', [$company, $row], absolute: false) : route('clients.proposal', [$workspace, $company, $row], absolute: false)) : null,
                    SearchResultKind::Time => $row instanceof ClientTimeEntry ? $make($kind, $row->public_id,
                        Str::limit((string) ($isPortal ? $row->client_visible_description : $row->description), 120),
                        $row->worked_on->toDateString().' - '.($row->project instanceof ClientProject ? $row->project->name.' - ' : '').$company->name,
                        $isPortal ? route('portal.time', $company, absolute: false) : route('clients.time', [$workspace, $company], absolute: false)) : null,
                    SearchResultKind::Contact => $row instanceof ClientContact ? $make($kind, $row->public_id, trim($row->first_name.' '.$row->last_name),
                        $company->name, route('clients.settings', [$workspace, $company], absolute: false)) : null,
                    SearchResultKind::PortalUser => $row instanceof ClientCompanyMembership ? $make($kind, $row->public_id, (string) $row->getAttribute('palette_name'),
                        'Portal access - '.$company->name, route('clients.settings', [$workspace, $company], absolute: false)) : null,
                    default => null,
                };
                if ($result instanceof SearchResult) {
                    $results[] = $result;
                }
            }
        }

        foreach ($files ?? [] as $file) {
            $companyId = $fileParents[$file->record_type.':'.$file->record_public_id] ?? null;
            $company = $companyId === null ? null : $companies->get($companyId);
            if (! $company instanceof ClientCompany) {
                continue;
            }
            $href = $this->fileHref($workspace, $company, $file, $isPortal);
            if ($href !== null) {
                $results[] = $make(SearchResultKind::File, $file->public_id, (string) $file->original_filename,
                    Str::headline($file->record_type).' - '.$company->name, $href);
            }
        }

        return $results;
    }

    /**
     * @param  Builder<ClientCompany>  $companies
     * @param  list<int>|null  $viewable
     * @return Collection<int, ClientExpense>
     */
    private function expenses(Workspace $workspace, SearchLookup $lookup, Builder $companies, ?array $viewable): Collection
    {
        return ClientExpense::query()->where('workspace_id', $workspace->id)
            ->whereIn('client_company_id', $companies)
            // The Expenses tab shows a scoped member the expenses of projects
            // they hold; an expense naming no project is a manager's.
            ->when($viewable !== null, fn (Builder $q) => $q->whereIn('client_project_id', $viewable ?? []))
            ->tap(fn (Builder $q) => $lookup->constrain($q, SearchColumn::Description, SearchResultKind::Expense))
            ->orderByDesc('spent_on')->orderByDesc('id')->limit($lookup->limit(SearchResultKind::Expense))->get();
    }

    /**
     * @param  Builder<ClientCompany>  $companies
     * @return Collection<int, ClientAgreement>
     */
    private function agreements(User $user, Workspace $workspace, SearchLookup $lookup, Builder $companies, bool $isPortal): Collection
    {
        $query = ClientAgreement::query()->where('workspace_id', $workspace->id)->whereIn('client_company_id', $companies);
        if ($isPortal) {
            $query->where('is_visible_to_client', true)->where('status', '!=', 'draft');
            $this->withinPortalProjects($query, $user, $workspace, 'client_agreements');
        } else {
            $query = $this->billing->constrainAgreements($query, $user, $workspace);
            $this->withinCompanyProjects($query, $workspace, 'client_agreements');
        }

        return $query->tap(fn (Builder $q) => $lookup->constrain($q, SearchColumn::Title, SearchResultKind::Agreement))
            ->orderByDesc('updated_at')->orderByDesc('id')->limit($lookup->limit(SearchResultKind::Agreement))->get();
    }

    /**
     * @param  Builder<ClientCompany>  $companies
     * @param  list<int>|null  $viewable
     * @return Collection<int, ClientProposal>
     */
    private function proposals(User $user, Workspace $workspace, SearchLookup $lookup, Builder $companies, bool $isPortal, ?array $viewable): Collection
    {
        $query = ClientProposal::query()->where('workspace_id', $workspace->id)->whereIn('client_company_id', $companies);
        if ($isPortal) {
            $query->where('is_visible_to_client', true)->whereIn('status', ['sent', 'accepted']);
            $this->withinPortalProjects($query, $user, $workspace, 'client_proposals');
        } else {
            $this->withinCompanyProjects($query, $workspace, 'client_proposals');
            // A project proposal needs the project; one for the whole client needs only the client.
            $query->when($viewable !== null, fn (Builder $q) => $q->where(fn (Builder $scope) => $scope
                ->whereNull('client_project_id')->orWhereIn('client_project_id', $viewable ?? [])));
        }

        return $query->tap(fn (Builder $q) => $lookup->constrain($q, SearchColumn::Title, SearchResultKind::Proposal))
            ->orderByDesc('updated_at')->orderByDesc('id')->limit($lookup->limit(SearchResultKind::Proposal))->get();
    }

    /**
     * @param  Builder<ClientCompany>  $companies
     * @param  list<int>|null  $viewable
     * @return Collection<int, ClientTimeEntry>
     */
    private function timeEntries(User $user, Workspace $workspace, SearchLookup $lookup, Builder $companies, bool $isPortal, ?array $viewable): Collection
    {
        if ($isPortal) {
            // The portal time page's three conditions: approved, cleared for the
            // client, and a client-safe description. The internal note is never
            // matched, so a search cannot confirm text the client may not read.
            $query = ClientTimeEntry::query()->where('workspace_id', $workspace->id)->whereIn('client_company_id', $companies)
                ->where('is_visible_to_client', true)->approved()->whereNotNull('client_visible_description')
                ->whereIn('client_project_id', ClientProject::query()
                    ->whereColumn('client_projects.client_company_id', 'client_time_entries.client_company_id')
                    ->whereIn('client_projects.id', $viewable ?? [])->select('client_projects.id'));
            $column = SearchColumn::ClientVisibleDescription;
        } else {
            $query = $this->time->visibleTo($user, $workspace)->whereIn('client_company_id', $companies)
                ->when($viewable !== null, fn (Builder $q) => $q->whereIn('client_project_id', $viewable ?? []));
            $column = SearchColumn::Description;
        }

        return $query->tap(fn (Builder $q) => $lookup->constrain($q, $column, SearchResultKind::Time))
            ->orderByDesc('worked_on')->orderByDesc('id')->limit($lookup->limit(SearchResultKind::Time))->get();
    }

    /**
     * Contacts, matched word by word so "jane doe" finds a Jane with a surname Doe.
     *
     * @param  Builder<ClientCompany>  $companies
     * @return Collection<int, ClientContact>
     */
    private function contacts(Workspace $workspace, SearchLookup $lookup, Builder $companies): Collection
    {
        $query = ClientContact::query()->where('workspace_id', $workspace->id)->where('is_archived', false)->whereIn('client_company_id', $companies);
        foreach ($this->words((string) $lookup->term) as $word) {
            $needle = '%'.WorkspaceSearch::escapeForLike($word).'%';
            $query->where(fn (Builder $match) => $match->whereRaw(SearchColumn::FirstName->likeSql(), [$needle])
                ->orWhereRaw(SearchColumn::LastName->likeSql(), [$needle])
                ->orWhereRaw(SearchColumn::Email->likeSql(), [$needle]));
        }

        return $query->orderBy('last_name')->orderBy('first_name')->orderBy('id')->limit($lookup->limit(SearchResultKind::Contact))->get();
    }

    /**
     * People with portal access to a client, found by name or email.
     *
     * @param  Builder<ClientCompany>  $companies
     * @return Collection<int, ClientCompanyMembership>
     */
    private function memberships(Workspace $workspace, SearchLookup $lookup, Builder $companies): Collection
    {
        $users = User::query();
        foreach ($this->words((string) $lookup->term) as $word) {
            $needle = '%'.WorkspaceSearch::escapeForLike($word).'%';
            $users->where(fn (Builder $match) => $match->whereRaw(SearchColumn::Name->likeSql(), [$needle])
                ->orWhereRaw(SearchColumn::Email->likeSql(), [$needle]));
        }

        /** @var Collection<int, ClientCompanyMembership> $rows */
        $rows = ClientCompanyMembership::query()->where('workspace_id', $workspace->id)->whereIn('client_company_id', $companies)
            ->whereIn('user_id', $users->select('id'))->orderByDesc('id')->limit($lookup->limit(SearchResultKind::PortalUser))->get();

        $names = $rows->isEmpty() ? collect() : User::query()->whereIn('id', $rows->pluck('user_id')->all())->pluck('name', 'id');
        foreach ($rows as $row) {
            $row->setAttribute('palette_name', (string) ($names[$row->user_id] ?? ''));
        }

        return $rows;
    }

    /**
     * Files the viewer may read, by filename, through the reader every file
     * screen uses.
     *
     * ## Matched in PHP, over a window
     *
     * `original_filename` is encrypted at rest - a filename can say as much as
     * the document - so the database cannot run a LIKE over it, and storing a
     * plaintext copy to make that possible would undo the encryption. The
     * authorized query therefore returns the newest {@see FILE_WINDOW} readable
     * files (one query, one column decrypted per row) and the term is matched
     * against those. A file older than the window is still found by browsing its
     * record; the palette is for what you were just working on. Recents ask by
     * id and have no window.
     *
     * @return Collection<int, ClientAttachment>
     */
    private function attachments(User $user, Workspace $workspace, SearchLookup $lookup, bool $isPortal): Collection
    {
        $types = AttachmentRecordResolver::uploadableTypes();
        if ($isPortal) {
            // A receipt is a download, not a page the palette can navigate to.
            $types = array_values(array_filter($types, fn (string $type): bool => AttachmentReader::portalReadsParentType($type) && $type !== 'expense'));
        }

        $query = $this->files->queryForTypes($user, $workspace, $isPortal ? AttachmentAudience::Portal : AttachmentAudience::Internal, $types)
            ->where('lifecycle_state', ClientAttachment::STATE_AVAILABLE)
            ->orderByDesc('created_at')->orderByDesc('id');

        if ($lookup->term === null) {
            return $query->whereIn('client_attachments.public_id', $lookup->ids[SearchResultKind::File->value] ?? [])->limit($lookup->limit(SearchResultKind::File))->get();
        }

        $term = $lookup->term;

        return $query->limit(self::FILE_WINDOW)->get()
            ->filter(fn (ClientAttachment $file): bool => mb_stripos((string) $file->original_filename, $term) !== false)
            ->take($lookup->limit(SearchResultKind::File))->values();
    }

    /**
     * The client each file's parent record belongs to, in one query.
     *
     * A union over the parent types present, so the cost is a single statement
     * whatever mix of invoices, tasks, receipts and the rest the matched files
     * hang off - which is what keeps the palette's query count flat.
     *
     * @param  Collection<int, ClientAttachment>  $files
     * @return array<string, int> client id, by "type:record public id"
     */
    private function fileParents(Workspace $workspace, Collection $files): array
    {
        $tables = [
            'company' => 'client_companies', 'project' => 'client_projects', 'proposal' => 'client_proposals',
            'expense' => 'client_expenses', 'invoice' => 'client_invoices', 'agreement' => 'client_agreements',
        ];
        $union = null;

        foreach ($files->groupBy('record_type') as $type => $group) {
            $type = (string) $type;
            $ids = $group->pluck('record_public_id')->unique()->values()->all();
            if ($type === 'task') {
                $query = DB::table('client_tasks')
                    ->join('client_projects', fn ($join) => $join->on('client_projects.id', '=', 'client_tasks.client_project_id')
                        ->on('client_projects.workspace_id', '=', 'client_tasks.workspace_id'))
                    ->where('client_tasks.workspace_id', $workspace->id)->whereIn('client_tasks.public_id', $ids)
                    ->selectRaw('? as record_type', ['task'])->addSelect('client_tasks.public_id as record_public_id', 'client_projects.client_company_id as company_id');
            } elseif (isset($tables[$type])) {
                $query = DB::table($tables[$type])->where('workspace_id', $workspace->id)->whereIn('public_id', $ids)
                    ->selectRaw('? as record_type', [$type])
                    ->addSelect('public_id as record_public_id', $type === 'company' ? 'id as company_id' : 'client_company_id as company_id');
            } else {
                continue;
            }
            $union = $union === null ? $query : $union->unionAll($query);
        }

        $parents = [];
        foreach ($union?->get() ?? [] as $row) {
            $parents[$row->record_type.':'.$row->record_public_id] = (int) $row->company_id;
        }

        return $parents;
    }

    private function fileHref(Workspace $workspace, ClientCompany $company, ClientAttachment $file, bool $isPortal): ?string
    {
        $type = (string) $file->record_type;
        if ($isPortal) {
            if ($type === 'agreement') {
                return route('portal.agreement', [$company, $file->record_public_id], absolute: false);
            }

            return in_array($type, ['proposal', 'invoice', 'project', 'task'], true)
                ? route('portal.files.parent.show', [$company, $type, $file->record_public_id], absolute: false) : null;
        }
        if ($type === 'expense') {
            return route('svc.expenses.receipts', [$workspace, $company, $file->record_public_id], absolute: false);
        }

        return in_array($type, ['company', 'project', 'task', 'proposal', 'agreement', 'invoice'], true)
            ? route('svc.files.parent.show', [$workspace, $type, $file->record_public_id], absolute: false) : null;
    }

    /**
     * A proposal or agreement either covers the whole client or names a project
     * that is one of that client's own.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function withinCompanyProjects(Builder $query, Workspace $workspace, string $table): void
    {
        $query->where(fn (Builder $scope) => $scope->whereNull($table.'.client_project_id')
            ->orWhereIn($table.'.client_project_id', ClientProject::query()->where('workspace_id', $workspace->id)
                ->whereColumn('client_projects.client_company_id', $table.'.client_company_id')->select('client_projects.id')));
    }

    /**
     * The portal controller's `withinScope`: a record naming no project passes,
     * one naming a project passes only when that project is one of the viewer's
     * grants within the same client.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function withinPortalProjects(Builder $query, User $user, Workspace $workspace, string $table): void
    {
        $query->where(fn (Builder $scope) => $scope->whereNull($table.'.client_project_id')
            ->orWhereIn($table.'.client_project_id', $this->portal->constrainProjectQuery(
                ClientProject::query()->where('workspace_id', $workspace->id)
                    ->whereColumn('client_projects.client_company_id', $table.'.client_company_id'),
                $user,
            )->select('client_projects.id')));
    }

    /**
     * The words of a term, bounded so a pasted paragraph cannot build a huge query.
     *
     * @return list<string>
     */
    private function words(string $term): array
    {
        return array_slice(preg_split('/\s+/', trim($term), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 5);
    }

    /**
     * @param  Collection<int, covariant \Illuminate\Database\Eloquent\Model>  $entries
     */
    private function loadProjects(Workspace $workspace, Collection $entries): void
    {
        $entries->load(['project' => fn ($q) => $q->where('workspace_id', $workspace->id)]);
    }
}
