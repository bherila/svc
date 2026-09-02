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
use App\Models\Workspace;
use App\Services\Authorization\BillingRecordAccess;
use App\Services\Authorization\ProjectAccess;
use App\Support\ClientHome\ClientHomeViewModel;
use App\Support\ClientHome\EngagementSummary;
use App\Support\ClientHome\InvoiceSummary;
use App\Support\ClientHome\TaskRow;
use App\Support\ClientHome\TimeEntryRow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Client Home as an operator sees it.
 *
 * Every read is bounded by two keys - the workspace and the company - never by
 * a child key alone. The schema carries independent foreign keys rather than
 * composite workspace/parent ones, so a row owned by another workspace can name
 * a company visible here, and keying on the company alone serializes it on its
 * parent's authority.
 *
 * Reaching the client is also not reaching all of it. Projects are narrowed to
 * what this viewer holds (#157) and money to what `BillingRecordAccess` allows,
 * because a member granted one project sees the work and the invoices
 * attributed to it, not the client's whole record.
 */
final class OperatorClientHomeQuery
{
    public function __construct(
        private readonly ProjectAccess $projects,
        private readonly BillingRecordAccess $billing,
    ) {}

    public function for(Workspace $workspace, ClientCompany $company, User $user): ClientHomeViewModel
    {
        $viewableProjectIds = $this->projects->viewableProjectIds($user, $workspace);

        $projects = ClientProject::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->when($viewableProjectIds !== null, fn (Builder $query): Builder => $query
                ->whereIn('id', $viewableProjectIds ?? []))
            ->orderBy('name')
            ->get();

        // Names resolved from the projects already read for this company rather
        // than looked up from an id on the row. A row pointing outside what the
        // viewer holds then renders as unscoped instead of disclosing a project
        // name from somewhere they cannot see.
        /** @var array<int, string> $projectNames */
        $projectNames = $projects->pluck('name', 'id')->all();
        /** @var list<int> $projectIds */
        $projectIds = $projects->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();

        return new ClientHomeViewModel(
            companyId: (string) $company->public_id,
            companyName: (string) $company->name,
            latestInvoice: $this->latestInvoice($workspace, $company, $user),
            recentTime: $this->recentTime($workspace, $company, $projectIds, $projectNames),
            openTasks: $this->openTasks($workspace, $projectIds, $projectNames),
            engagement: $this->engagement($workspace, $company, $user),
            links: [
                'invoices' => route('clients.invoices', [$workspace, $company], absolute: false),
                'time' => route('clients.time', [$workspace, $company], absolute: false),
                'tasks' => route('clients.tasks', [$workspace, $company], absolute: false),
            ],
            settingsHref: Gate::forUser($user)->allows('manage', $workspace)
                ? route('clients.settings', [$workspace, $company], absolute: false)
                : null,
        );
    }

    private function latestInvoice(Workspace $workspace, ClientCompany $company, User $user): ?InvoiceSummary
    {
        $invoice = $this->billing->constrainInvoices(
            ClientInvoice::query()
                ->where('workspace_id', $workspace->id)
                ->where('client_company_id', $company->id),
            $user,
            $workspace,
        )
            // Deterministic, with the id as the tie-breaker: two invoices issued
            // the same day otherwise take turns being "latest" between renders.
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
            href: route('clients.invoice', [$workspace, $company, $invoice], absolute: false),
        );
    }

    /**
     * @param  list<int>  $projectIds
     * @param  array<int, string>  $projectNames
     * @return list<TimeEntryRow>
     */
    private function recentTime(
        Workspace $workspace,
        ClientCompany $company,
        array $projectIds,
        array $projectNames,
    ): array {
        if ($projectIds === []) {
            return [];
        }

        return array_values(ClientTimeEntry::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->whereIn('client_project_id', $projectIds)
            ->orderByDesc('worked_on')
            ->orderByDesc('id')
            ->limit(ClientHomeViewModel::RECENT_TIME)
            ->get()
            ->map(fn (ClientTimeEntry $entry): TimeEntryRow => new TimeEntryRow(
                id: (string) $entry->public_id,
                workedOn: $entry->worked_on->toDateString(),
                project: $projectNames[(int) $entry->client_project_id] ?? null,
                description: $entry->description,
                minutes: (int) $entry->minutes,
            ))
            ->all());
    }

    /**
     * @param  list<int>  $projectIds
     * @param  array<int, string>  $projectNames
     * @return list<TaskRow>
     */
    private function openTasks(Workspace $workspace, array $projectIds, array $projectNames): array
    {
        if ($projectIds === []) {
            return [];
        }

        return array_values(ClientTask::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('client_project_id', $projectIds)
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

    private function engagement(Workspace $workspace, ClientCompany $company, User $user): EngagementSummary
    {
        $agreement = $this->billing->constrainAgreements(
            ClientAgreement::query()
                ->where('workspace_id', $workspace->id)
                ->where('client_company_id', $company->id)
                ->where('status', 'active'),
            $user,
            $workspace,
        )->orderByDesc('starts_on')->orderByDesc('id')->first();

        // Only a proposal that is waiting on someone. An accepted one is
        // history, and history belongs in the module rather than in the banner
        // that says what needs attention.
        $proposal = ClientProposal::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->where('status', 'sent')
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->first();

        return new EngagementSummary(
            agreementTitle: $agreement?->title,
            agreementStatus: $agreement?->status,
            agreementCadence: $agreement?->billing_cadence,
            agreementHref: $agreement === null
                ? null
                : route('clients.agreement', [$workspace, $company, $agreement], absolute: false),
            proposalTitle: $proposal?->title,
            proposalStatus: $proposal?->status,
            proposalHref: $proposal === null
                ? null
                : route('clients.proposal', [$workspace, $company, $proposal], absolute: false),
        );
    }
}
