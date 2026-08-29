<?php

namespace App\Http\Controllers;

use App\Models\ClientAgreement;
use App\Models\ClientAttachment;
use App\Models\ClientBillingSchedule;
use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientInvoice;
use App\Models\ClientProposal;
use App\Models\ClientTimeEntry;
use App\Models\Workspace;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceOperationsController extends Controller
{
    public function __invoke(Workspace $workspace): Response
    {
        Gate::authorize('view', $workspace);

        $companies = $workspace->clientCompanies()
            ->with(['projects' => fn ($query) => $query->orderBy('name')])
            ->orderBy('name')
            ->get();
        $companyIds = $companies->pluck('id')->all();
        $projectIds = $companies
            ->flatMap(fn (ClientCompany $company): array => $company->projects->modelKeys())
            ->all();

        $timeEntriesByProject = $projectIds === []
            ? collect()
            // The ranked derived table already applies ClientTimeEntry's soft-delete
            // scope. Applying it a second time to the outer query would qualify
            // `client_time_entries.deleted_at`, but that table is named
            // `ranked_time_entries` at that level.
            : ClientTimeEntry::withoutGlobalScopes()
                ->fromSub(
                    ClientTimeEntry::query()
                        ->select('client_time_entries.*')
                        ->selectRaw(
                            'ROW_NUMBER() OVER (PARTITION BY client_project_id ORDER BY worked_on DESC, id DESC) AS operation_row_number',
                        )
                        ->where('workspace_id', $workspace->id)
                        ->whereIn('client_project_id', $projectIds),
                    'ranked_time_entries',
                )
                ->where('operation_row_number', '<=', 25)
                ->orderByDesc('worked_on')
                ->orderByDesc('id')
                ->get()
                ->groupBy('client_project_id');

        $proposals = $companyIds === []
            ? collect()
            : ClientProposal::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('client_company_id', $companyIds)
                ->with('items')
                ->latest('id')
                ->get();
        $proposalsByCompany = $proposals->groupBy('client_company_id');

        $agreements = $companyIds === []
            ? collect()
            : ClientAgreement::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('client_company_id', $companyIds)
                ->latest('id')
                ->get();
        $agreementsByCompany = $agreements->groupBy('client_company_id');

        $schedules = $companyIds === []
            ? collect()
            : ClientBillingSchedule::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('client_company_id', $companyIds)
                ->with('agreement')
                ->latest('id')
                ->get();
        $schedulesByCompany = $schedules->groupBy('client_company_id');

        $invoices = $companyIds === []
            ? collect()
            : ClientInvoice::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('client_company_id', $companyIds)
                ->latest('id')
                ->get();
        $invoicesByCompany = $invoices->groupBy('client_company_id');

        $activitiesByCompany = $companyIds === []
            ? collect()
            : ClientCompanyActivity::query()
                ->fromSub(
                    ClientCompanyActivity::query()
                        ->select('client_company_activity.*')
                        ->selectRaw(
                            'ROW_NUMBER() OVER (PARTITION BY client_company_id ORDER BY created_at DESC, id DESC) AS activity_row_number',
                        )
                        ->where('workspace_id', $workspace->id)
                        ->whereIn('client_company_id', $companyIds),
                    'ranked_company_activity',
                )
                ->with('actor:id,name')
                ->where('activity_row_number', '<=', 100)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get()
                ->groupBy('client_company_id');

        $attachmentRecordIds = [
            'proposal' => $proposals->pluck('public_id')->all(),
            'agreement' => $agreements->pluck('public_id')->all(),
            'invoice' => $invoices->pluck('public_id')->all(),
        ];
        $attachments = collect();

        if (collect($attachmentRecordIds)->contains(fn (array $recordIds): bool => $recordIds !== [])) {
            $attachments = ClientAttachment::query()
                ->select(['public_id', 'record_type', 'record_public_id', 'original_filename', 'media_type', 'bytes'])
                ->where('workspace_id', $workspace->id)
                ->where('lifecycle_state', ClientAttachment::STATE_AVAILABLE)
                ->where(function ($query) use ($attachmentRecordIds): void {
                    $query
                        ->where(fn ($query) => $query
                            ->where('record_type', 'proposal')
                            ->whereIn('record_public_id', $attachmentRecordIds['proposal']))
                        ->orWhere(fn ($query) => $query
                            ->where('record_type', 'agreement')
                            ->whereIn('record_public_id', $attachmentRecordIds['agreement']))
                        ->orWhere(fn ($query) => $query
                            ->where('record_type', 'invoice')
                            ->whereIn('record_public_id', $attachmentRecordIds['invoice']));
                })
                ->get()
                ->groupBy(fn (ClientAttachment $attachment): string => $attachment->record_type.':'.$attachment->record_public_id);
        }
        $attachmentPayload = fn (string $type, string $recordPublicId): array => ($attachments->get($type.':'.$recordPublicId) ?? collect())
            ->map(fn (ClientAttachment $attachment): array => [
                'id' => $attachment->public_id,
                'name' => $attachment->original_filename,
                'media_type' => $attachment->media_type,
                'bytes' => $attachment->bytes,
                'download_url' => "/workspaces/{$workspace->public_id}/attachments/{$attachment->public_id}",
            ])->values()->all();

        $clients = [];

        foreach ($companies as $company) {
            $projects = [];

            foreach ($company->projects as $project) {
                $projects[] = [
                    'id' => $project->public_id,
                    'name' => $project->name,
                    'time_entries' => ($timeEntriesByProject->get($project->id) ?? collect())
                        ->map(fn (ClientTimeEntry $entry): array => [
                            'id' => $entry->public_id,
                            'worked_on' => $entry->worked_on->toDateString(),
                            'minutes' => $entry->minutes,
                            'description' => $entry->description,
                            'status' => $entry->status,
                            'is_billable' => $entry->is_billable,
                            'is_deferred' => $entry->is_deferred,
                        ])
                        ->all(),
                ];
            }

            $clients[] = [
                'id' => $company->public_id,
                'name' => $company->name,
                'projects' => $projects,
                'proposals' => ($proposalsByCompany->get($company->id) ?? collect())
                    ->map(fn (ClientProposal $proposal): array => [
                        'id' => $proposal->public_id,
                        'title' => $proposal->title,
                        'status' => $proposal->status,
                        'total_amount' => $proposal->totalAmount(),
                        'currency' => $proposal->currency,
                        'attachments' => $attachmentPayload('proposal', $proposal->public_id),
                    ])
                    ->all(),
                'agreements' => ($agreementsByCompany->get($company->id) ?? collect())
                    ->map(fn (ClientAgreement $agreement): array => [
                        'id' => $agreement->public_id,
                        'title' => $agreement->title,
                        'status' => $agreement->status,
                        'billing_cadence' => $agreement->billing_cadence,
                        'attachments' => $attachmentPayload('agreement', $agreement->public_id),
                    ])
                    ->all(),
                'billing_schedules' => ($schedulesByCompany->get($company->id) ?? collect())
                    ->map(fn (ClientBillingSchedule $schedule): array => [
                        'id' => $schedule->public_id,
                        'agreement_id' => $schedule->agreement->public_id,
                        'cadence' => $schedule->cadence,
                        'next_run_on' => $schedule->next_run_on->toDateString(),
                        'is_active' => $schedule->is_active,
                    ])
                    ->all(),
                'invoices' => ($invoicesByCompany->get($company->id) ?? collect())
                    ->map(fn (ClientInvoice $invoice): array => [
                        'id' => $invoice->public_id,
                        'invoice_number' => $invoice->invoice_number,
                        'status' => $invoice->status,
                        'total_amount' => $invoice->total_amount,
                        'paid_amount' => $invoice->paid_amount,
                        'balance_amount' => $invoice->balance_amount,
                        'currency' => $invoice->currency,
                        'due_date' => $invoice->due_date?->toDateString(),
                        'attachments' => $attachmentPayload('invoice', $invoice->public_id),
                    ])
                    ->all(),
                'activities' => ($activitiesByCompany->get($company->id) ?? collect())
                    ->map(fn (ClientCompanyActivity $activity): array => [
                        'id' => $activity->public_id,
                        'action' => $activity->action,
                        'actor_name' => $activity->actor?->name,
                        'payload' => $activity->payload ?? [],
                        'created_at' => $activity->created_at?->toISOString(),
                    ])
                    ->all(),
            ];
        }

        return Inertia::render('operations', [
            'workspace' => [
                'id' => $workspace->public_id,
                'name' => $workspace->name,
                'clients' => $clients,
                // The browser's calendar is not the workspace's, and neither
                // is UTC's. Defaulting a date from `toISOString()` gives UTC's
                // day, which the write validators - bounded by the workspace's
                // own window - refuse for the hours the two disagree. The
                // calendar travels rather than a date, because this page can
                // sit open past its own midnight.
                'timezone' => $workspace->timezone,
            ],
        ]);
    }
}
