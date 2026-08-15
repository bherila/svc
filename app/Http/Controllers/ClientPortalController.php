<?php

namespace App\Http\Controllers;

use App\Models\ClientAgreement;
use App\Models\ClientAttachment;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProposal;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ClientPortalController extends Controller
{
    public function show(ClientCompany $clientCompany): Response
    {
        Gate::authorize('viewPortal', $clientCompany);

        $workspace = $clientCompany->workspace;

        $clientCompany->load(['projects' => function ($query) use ($clientCompany): void {
            $query->where('workspace_id', $clientCompany->workspace_id)
                ->where('is_visible_to_client', true)
                ->with(['tasks' => fn ($taskQuery) => $taskQuery
                    ->where('workspace_id', $clientCompany->workspace_id)
                    ->where('is_visible_to_client', true)]);
        }]);

        $proposals = ClientProposal::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $clientCompany->id)
            ->where('is_visible_to_client', true)
            ->whereIn('status', ['sent', 'accepted'])
            ->with(['items' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')])
            ->latest('id')
            ->get();

        $agreements = ClientAgreement::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $clientCompany->id)
            ->where('is_visible_to_client', true)
            ->where('status', '!=', 'draft')
            ->latest('id')
            ->get();

        $invoices = ClientInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $clientCompany->id)
            ->where('is_visible_to_client', true)
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->latest('id')
            ->get();

        $visibleRecords = [
            'proposal' => $proposals->pluck('public_id')->all(),
            'agreement' => $agreements->pluck('public_id')->all(),
            'invoice' => $invoices->pluck('public_id')->all(),
        ];
        $attachments = ClientAttachment::query()
            ->where('workspace_id', $workspace->id)
            ->where('lifecycle_state', ClientAttachment::STATE_AVAILABLE)
            ->where(function ($query) use ($visibleRecords): void {
                foreach ($visibleRecords as $type => $recordPublicIds) {
                    $query->orWhere(function ($recordQuery) use ($type, $recordPublicIds): void {
                        $recordQuery->where('record_type', $type)->whereIn('record_public_id', $recordPublicIds);
                    });
                }
            })
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

        $projectPayload = [];

        foreach ($clientCompany->projects as $project) {
            $taskPayload = [];

            foreach ($project->tasks as $task) {
                $taskPayload[] = [
                    'id' => $task->public_id,
                    'title' => $task->title,
                    'description' => $task->description,
                    'status' => $task->status,
                ];
            }

            $projectPayload[] = [
                'id' => $project->public_id,
                'name' => $project->name,
                'description' => $project->description,
                'status' => $project->status,
                'tasks' => $taskPayload,
            ];
        }

        $proposalPayload = [];
        foreach ($proposals as $proposal) {
            $items = [];
            foreach ($proposal->items as $item) {
                $items[] = [
                    'id' => $item->public_id,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_amount' => $item->unit_amount,
                    'cadence' => $item->cadence,
                ];
            }
            $proposalPayload[] = [
                'id' => $proposal->public_id,
                'title' => $proposal->title,
                'summary' => $proposal->summary,
                'terms' => $proposal->terms,
                'currency' => $proposal->currency,
                'valid_until' => $proposal->valid_until?->toDateString(),
                'status' => $proposal->status,
                'sent_at' => $proposal->sent_at?->toISOString(),
                'accepted_at' => $proposal->accepted_at?->toISOString(),
                'attachments' => $attachmentPayload('proposal', $proposal->public_id),
                'items' => $items,
            ];
        }

        $agreementPayload = [];
        foreach ($agreements as $agreement) {
            $agreementPayload[] = [
                'id' => $agreement->public_id,
                'title' => $agreement->title,
                'status' => $agreement->status,
                'starts_on' => $agreement->starts_on?->toDateString(),
                'ends_on' => $agreement->ends_on?->toDateString(),
                'agreement_text' => $agreement->agreement_text,
                'currency' => $agreement->currency,
                'hourly_rate_amount' => $agreement->hourly_rate_amount,
                'retainer_amount' => $agreement->retainer_amount,
                'retainer_minutes' => $agreement->retainer_minutes,
                'billing_cadence' => $agreement->billing_cadence,
                'rollover_policy' => $agreement->rollover_policy,
                'signed_at' => $agreement->signed_at?->toISOString(),
                'signer_name' => $agreement->signer_name,
                'signer_title' => $agreement->signer_title,
                'attachments' => $attachmentPayload('agreement', $agreement->public_id),
            ];
        }

        $invoicePayload = [];
        foreach ($invoices as $invoice) {
            $invoicePayload[] = [
                'id' => $invoice->public_id,
                'invoice_number' => $invoice->invoice_number,
                'issue_date' => $invoice->issue_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'service_period_start' => $invoice->service_period_start?->toDateString(),
                'service_period_end' => $invoice->service_period_end?->toDateString(),
                'currency' => $invoice->currency,
                'subtotal_amount' => $invoice->subtotal_amount,
                'tax_amount' => $invoice->tax_amount,
                'total_amount' => $invoice->total_amount,
                'paid_amount' => $invoice->paid_amount,
                'balance_amount' => $invoice->balance_amount,
                'status' => $invoice->status,
                'pdf_url' => "/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/pdf",
                'attachments' => $attachmentPayload('invoice', $invoice->public_id),
            ];
        }

        return Inertia::render('portal', [
            'company' => [
                'id' => $clientCompany->public_id,
                'name' => $clientCompany->name,
                'proposals' => $proposalPayload,
                'agreements' => $agreementPayload,
                'invoices' => $invoicePayload,
                'projects' => $projectPayload,
            ],
        ]);
    }
}
