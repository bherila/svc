<?php

namespace App\Http\Controllers;

use App\Models\ClientAgreement;
use App\Models\ClientAttachment;
use App\Models\ClientBillingSchedule;
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

        $workspace->load([
            'clientCompanies' => fn ($query) => $query->orderBy('name'),
            'clientCompanies.projects' => fn ($query) => $query->orderBy('name'),
        ]);

        $attachments = ClientAttachment::query()
            ->where('workspace_id', $workspace->id)
            ->where('lifecycle_state', ClientAttachment::STATE_AVAILABLE)
            ->get()
            ->groupBy(fn (ClientAttachment $attachment): string => $attachment->record_type.':'.$attachment->record_public_id);
        $attachmentPayload = fn (string $type, string $recordPublicId): array => ($attachments->get($type.':'.$recordPublicId) ?? collect())
            ->map(fn (ClientAttachment $attachment): array => [
                'id' => $attachment->public_id,
                'name' => $attachment->original_filename,
                'media_type' => $attachment->media_type,
                'bytes' => $attachment->bytes,
                'download_url' => "/workspaces/{$workspace->public_id}/attachments/{$attachment->public_id}",
            ])->values()->all();

        $clients = [];

        foreach ($workspace->clientCompanies as $company) {
            $projects = [];

            foreach ($company->projects as $project) {
                $projects[] = [
                    'id' => $project->public_id,
                    'name' => $project->name,
                    'time_entries' => ClientTimeEntry::query()
                        ->where('workspace_id', $workspace->id)
                        ->where('client_project_id', $project->id)
                        ->latest('worked_on')
                        ->latest('id')
                        ->limit(25)
                        ->get()
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
                'proposals' => ClientProposal::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('client_company_id', $company->id)
                    ->with('items')
                    ->latest('id')
                    ->get()
                    ->map(fn (ClientProposal $proposal): array => [
                        'id' => $proposal->public_id,
                        'title' => $proposal->title,
                        'status' => $proposal->status,
                        'total_amount' => $proposal->totalAmount(),
                        'currency' => $proposal->currency,
                        'attachments' => $attachmentPayload('proposal', $proposal->public_id),
                    ])
                    ->all(),
                'agreements' => ClientAgreement::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('client_company_id', $company->id)
                    ->latest('id')
                    ->get()
                    ->map(fn (ClientAgreement $agreement): array => [
                        'id' => $agreement->public_id,
                        'title' => $agreement->title,
                        'status' => $agreement->status,
                        'billing_cadence' => $agreement->billing_cadence,
                        'attachments' => $attachmentPayload('agreement', $agreement->public_id),
                    ])
                    ->all(),
                'billing_schedules' => ClientBillingSchedule::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('client_company_id', $company->id)
                    ->with('agreement')
                    ->latest('id')
                    ->get()
                    ->map(fn (ClientBillingSchedule $schedule): array => [
                        'id' => $schedule->public_id,
                        'agreement_id' => $schedule->agreement->public_id,
                        'cadence' => $schedule->cadence,
                        'next_run_on' => $schedule->next_run_on->toDateString(),
                        'is_active' => $schedule->is_active,
                    ])
                    ->all(),
                'invoices' => ClientInvoice::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('client_company_id', $company->id)
                    ->latest('id')
                    ->get()
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
            ];
        }

        return Inertia::render('operations', [
            'workspace' => [
                'id' => $workspace->public_id,
                'name' => $workspace->name,
                'clients' => $clients,
            ],
        ]);
    }
}
