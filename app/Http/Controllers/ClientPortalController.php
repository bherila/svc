<?php

namespace App\Http\Controllers;

use App\Models\ClientAgreement;
use App\Models\ClientAttachment;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProposal;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Services\Authorization\PortalAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ClientPortalController extends Controller
{
    public function __construct(private readonly PortalAccess $portalAccess) {}

    /**
     * The invoices this company's portal may show, as a query.
     *
     * Three conditions, and each is load-bearing. The workspace and company
     * bound it to this tenant and this client; `is_visible_to_client` is the
     * operator's own decision about disclosure; and the status list keeps a
     * draft out - a draft is working arithmetic, and showing a client a figure
     * nobody has committed to invites an argument about a number that was
     * never sent.
     *
     * Shared between the list and the detail rather than restated, because a
     * detail screen that admitted one invoice the list would not is the whole
     * bug: the client never sees the row, and reaches it by id.
     *
     * @return Builder<ClientInvoice>
     */
    private function visibleInvoices(ClientCompany $clientCompany): Builder
    {
        return ClientInvoice::query()
            ->where('workspace_id', $clientCompany->workspace_id)
            ->where('client_company_id', $clientCompany->id)
            ->where('is_visible_to_client', true)
            ->whereIn('status', ['issued', 'partially_paid', 'paid']);
    }

    /**
     * One invoice, for the client it was sent to.
     *
     * Read-only, like everything a client can reach. The lines are the point:
     * the list says what is owed and this says what for, which is the question
     * a client actually opens a portal to answer.
     *
     * Line rows are narrowed to what belongs on an invoice a client is looking
     * at - description, quantity, hours and money. The internal agreement and
     * recurring-item keys the model already hides are not reintroduced here by
     * a hand-built array.
     */
    public function invoice(ClientCompany $clientCompany, ClientInvoice $clientInvoice): Response
    {
        Gate::authorize('viewPortal', $clientCompany);

        // Resolved through the same query the list uses, so an invoice the
        // client cannot see is not found rather than merely unlinked.
        $invoice = $this->visibleInvoices($clientCompany)
            ->whereKey($clientInvoice->getKey())
            ->first();

        abort_if($invoice === null, 404);

        $lines = ClientInvoiceLine::query()
            ->where('workspace_id', $clientCompany->workspace_id)
            ->where('client_invoice_id', $invoice->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return Inertia::render('portal/invoice', [
            'company' => [
                'id' => $clientCompany->public_id,
                'name' => $clientCompany->name,
            ],
            'invoice' => [
                'id' => $invoice->public_id,
                'invoice_number' => $invoice->invoice_number,
                'status' => $invoice->status,
                'currency' => $invoice->currency,
                'issue_date' => $invoice->issue_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'service_period_start' => $invoice->service_period_start?->toDateString(),
                'service_period_end' => $invoice->service_period_end?->toDateString(),
                'subtotal_amount' => (int) $invoice->subtotal_amount,
                'tax_amount' => (int) $invoice->tax_amount,
                'total_amount' => (int) $invoice->total_amount,
                'paid_amount' => (int) $invoice->paid_amount,
                'balance_amount' => (int) $invoice->balance_amount,
                'pdf_url' => "/workspaces/{$clientCompany->workspace->public_id}/invoices/{$invoice->public_id}/pdf",
            ],
            'lines' => $lines->map(fn (ClientInvoiceLine $line): array => [
                'id' => $line->public_id,
                'description' => $line->description,
                'quantity' => (float) $line->quantity,
                'hours' => $line->hours === null ? null : (float) $line->hours,
                'line_date' => $line->line_date?->toDateString(),
                'unit_amount' => (int) $line->unit_amount,
                'total_amount' => (int) $line->total_amount,
            ])->values()->all(),
        ]);
    }

    public function show(ClientCompany $clientCompany): Response
    {
        Gate::authorize('viewPortal', $clientCompany);

        $workspace = $clientCompany->workspace;
        // The portal is a web surface; an agent principal never reaches it.
        $viewer = request()->user();
        $viewer = $viewer instanceof User ? $viewer : null;
        $visibleProjectIds = $this->portalAccess->visibleProjectIds($clientCompany, $viewer);

        $clientCompany->load(['projects' => function ($query) use ($clientCompany, $visibleProjectIds): void {
            $query->where('workspace_id', $clientCompany->workspace_id)
                ->where('is_visible_to_client', true)
                ->when($visibleProjectIds !== null, fn ($scoped) => $scoped->whereIn('id', $visibleProjectIds))
                ->with(['tasks' => fn ($taskQuery) => $taskQuery
                    ->where('workspace_id', $clientCompany->workspace_id)
                    ->where('is_visible_to_client', true)]);
        }]);

        $proposals = ClientProposal::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $clientCompany->id)
            ->where('is_visible_to_client', true)
            ->whereIn('status', ['sent', 'accepted'])
            // Proposals and agreements carry a project too, so a user narrowed
            // to one project must not read another's rates, retainer or terms -
            // nor accept a proposal that was never theirs.
            ->when(
                $visibleProjectIds !== null,
                fn ($query) => $query->where(fn ($scope) => $scope
                    ->whereNull('client_project_id')
                    ->orWhereIn('client_project_id', $visibleProjectIds ?? [])),
            )
            ->with(['items' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')])
            ->latest('id')
            ->get();

        $agreements = ClientAgreement::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $clientCompany->id)
            ->where('is_visible_to_client', true)
            ->where('status', '!=', 'draft')
            ->when(
                $visibleProjectIds !== null,
                fn ($query) => $query->where(fn ($scope) => $scope
                    ->whereNull('client_project_id')
                    ->orWhereIn('client_project_id', $visibleProjectIds ?? [])),
            )
            ->latest('id')
            ->get();

        $invoices = $this->visibleInvoices($clientCompany)->latest('id')->get();

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

        $timeByProject = ClientTimeEntry::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $clientCompany->id)
            ->where('is_visible_to_client', true)
            // Visibility is the worker's intent; approval is the gate. An entry
            // is created as a draft, so filtering on visibility alone showed
            // clients work nobody had approved - and work later rejected.
            ->approved()
            ->whereIn('client_project_id', $clientCompany->projects->pluck('id'))
            ->orderByDesc('worked_on')
            ->orderByDesc('id')
            ->get()
            ->groupBy('client_project_id');

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
                // Read-only. Rates, costs, and internal descriptions never cross this line;
                // no client-reachable route writes time.
                'time_entries' => ($timeByProject->get($project->id) ?? collect())
                    ->map(fn (ClientTimeEntry $entry): array => [
                        'id' => $entry->public_id,
                        'worked_on' => $entry->worked_on->toDateString(),
                        'minutes' => $entry->minutes,
                        // No fallback to the internal description. A row without a
                        // client-safe description has not been cleared for the
                        // client, and the internal note may say anything.
                        'description' => $entry->client_visible_description,
                    ])->values()->all(),
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
