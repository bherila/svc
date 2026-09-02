<?php

namespace App\Queries\ClientHome;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientProposal;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Services\Authorization\PortalAccess;
use App\Services\Authorization\PortalInvoiceQuery;
use App\Support\ClientHome\ClientHomeViewModel;
use App\Support\ClientHome\EngagementSummary;
use App\Support\ClientHome\InvoiceSummary;
use App\Support\ClientHome\TaskRow;
use App\Support\ClientHome\TimeEntryRow;
use Illuminate\Database\Eloquent\Builder;

/**
 * Client Home as the client sees it.
 *
 * Same shape as the operator's, and deliberately not the same queries. Every
 * read here is fail-closed on four independent conditions - the tenant, the
 * company, the operator's own visibility decision, and the record's lifecycle -
 * and a project-scoped portal user is narrowed again on top of that.
 *
 * The lifecycle conditions are the ones easiest to forget and worst to get
 * wrong. A draft invoice is working arithmetic; an unapproved time entry is
 * work that may yet be rejected; a draft agreement is a document nobody has
 * agreed to. Each was a real bug on some surface of this application before it
 * was written down, which is why they are conditions in a query rather than
 * filters applied while rendering.
 */
final class PortalClientHomeQuery
{
    public function __construct(
        private readonly PortalAccess $portalAccess,
        private readonly PortalInvoiceQuery $invoices,
    ) {}

    public function for(ClientCompany $company, User $viewer): ClientHomeViewModel
    {
        // Null means the whole company; an empty list means a scoped user who
        // was granted nothing, and the two must not collapse.
        $visibleProjectIds = $this->portalAccess->visibleProjectIds($company, $viewer);

        $projects = ClientProject::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->where('is_visible_to_client', true)
            ->when($visibleProjectIds !== null, fn (Builder $query): Builder => $query
                ->whereIn('id', $visibleProjectIds ?? []))
            ->orderBy('name')
            ->get();

        /** @var array<int, string> $projectNames */
        $projectNames = $projects->pluck('name', 'id')->all();
        /** @var list<int> $projectIds */
        $projectIds = $projects->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();

        return new ClientHomeViewModel(
            companyId: (string) $company->public_id,
            companyName: (string) $company->name,
            latestInvoice: $this->latestInvoice($company, $viewer),
            recentTime: $this->recentTime($company, $visibleProjectIds, $projectNames),
            openTasks: $this->openTasks($company, $projectIds, $projectNames),
            engagement: $this->engagement($company, $visibleProjectIds),
            links: [
                'invoices' => route('portal.invoices', $company, absolute: false),
                'time' => route('portal.time', $company, absolute: false),
                'tasks' => route('portal.tasks', $company, absolute: false),
            ],
            // Editing the client record is an operator act. Holding the
            // client's own login is not authority over it.
            settingsHref: null,
        );
    }

    private function latestInvoice(ClientCompany $company, User $viewer): ?InvoiceSummary
    {
        $invoice = $this->invoices->visibleTo($company, $viewer)
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->first();

        if (! $invoice instanceof ClientInvoice) {
            return null;
        }

        return new InvoiceSummary(
            id: (string) $invoice->public_id,
            invoiceNumber: (string) $invoice->invoice_number,
            status: (string) $invoice->status,
            currency: (string) $invoice->currency,
            issueDate: $invoice->issue_date?->toDateString(),
            dueDate: $invoice->due_date?->toDateString(),
            totalAmount: (int) $invoice->total_amount,
            paidAmount: (int) $invoice->paid_amount,
            balanceAmount: (int) $invoice->balance_amount,
            href: route('portal.invoice', [$company, $invoice], absolute: false),
        );
    }

    /**
     * @param  list<int>|null  $visibleProjectIds
     * @param  array<int, string>  $projectNames
     * @return list<TimeEntryRow>
     */
    private function recentTime(ClientCompany $company, ?array $visibleProjectIds, array $projectNames): array
    {
        return array_values(ClientTimeEntry::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->where('is_visible_to_client', true)
            // Visibility is the worker's intent; approval is the gate. An entry
            // is created as a draft, so filtering on visibility alone showed
            // clients work nobody had approved - and work later rejected.
            ->approved()
            // A row with no client-safe description has not been cleared for
            // the client at all, whatever the visibility flag says. Excluded
            // rather than shown blank, because a blank row still discloses that
            // work happened on a day, and the internal note is never the
            // fallback.
            ->whereNotNull('client_visible_description')
            ->when($visibleProjectIds !== null, fn (Builder $query): Builder => $query
                ->whereIn('client_project_id', $visibleProjectIds ?? []))
            ->orderByDesc('worked_on')
            ->orderByDesc('id')
            ->limit(ClientHomeViewModel::RECENT_TIME)
            ->get()
            ->map(fn (ClientTimeEntry $entry): TimeEntryRow => new TimeEntryRow(
                id: (string) $entry->public_id,
                workedOn: $entry->worked_on->toDateString(),
                project: $projectNames[(int) $entry->client_project_id] ?? null,
                description: $entry->client_visible_description,
                minutes: (int) $entry->minutes,
            ))
            ->all());
    }

    /**
     * @param  list<int>  $projectIds
     * @param  array<int, string>  $projectNames
     * @return list<TaskRow>
     */
    private function openTasks(ClientCompany $company, array $projectIds, array $projectNames): array
    {
        if ($projectIds === []) {
            return [];
        }

        return array_values(ClientTask::query()
            ->where('workspace_id', $company->workspace_id)
            ->whereIn('client_project_id', $projectIds)
            ->where('is_visible_to_client', true)
            ->where('status', '!=', 'completed')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(ClientHomeViewModel::OPEN_TASKS)
            ->get()
            ->map(fn (ClientTask $task): TaskRow => new TaskRow(
                id: (string) $task->public_id,
                title: (string) $task->title,
                project: $projectNames[(int) $task->client_project_id] ?? null,
                status: (string) $task->status,
            ))
            ->all());
    }

    /**
     * @param  list<int>|null  $visibleProjectIds
     */
    private function engagement(ClientCompany $company, ?array $visibleProjectIds): EngagementSummary
    {
        $agreement = ClientAgreement::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->where('is_visible_to_client', true)
            // A draft is a document nobody has agreed to.
            ->where('status', '!=', 'draft')
            // Proposals and agreements carry a project too, so a user narrowed
            // to one project must not read another's rates, retainer or terms -
            // nor accept a proposal that was never theirs.
            ->when($visibleProjectIds !== null, fn (Builder $query): Builder => $query
                ->where(fn (Builder $scope) => $scope
                    ->whereNull('client_project_id')
                    ->orWhereIn('client_project_id', $visibleProjectIds ?? [])))
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->first();

        $proposal = ClientProposal::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->where('is_visible_to_client', true)
            ->where('status', 'sent')
            ->when($visibleProjectIds !== null, fn (Builder $query): Builder => $query
                ->where(fn (Builder $scope) => $scope
                    ->whereNull('client_project_id')
                    ->orWhereIn('client_project_id', $visibleProjectIds ?? [])))
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->first();

        return new EngagementSummary(
            agreementTitle: $agreement?->title,
            agreementStatus: $agreement?->status,
            agreementCadence: $agreement?->billing_cadence,
            agreementHref: $agreement === null
                ? null
                : route('portal.agreement', [$company, $agreement], absolute: false),
            proposalTitle: $proposal?->title,
            proposalStatus: $proposal?->status,
            proposalHref: $proposal === null
                ? null
                : route('portal.proposal', [$company, $proposal], absolute: false),
        );
    }
}
