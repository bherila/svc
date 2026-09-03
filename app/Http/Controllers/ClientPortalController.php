<?php

namespace App\Http\Controllers;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientProposal;
use App\Models\ClientProposalItem;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Queries\ClientHome\PortalClientHomeQuery;
use App\Services\Authorization\PortalAccess;
use App\Services\Authorization\PortalInvoiceQuery;
use App\Support\Engagement\AgreementTermsPayload;
use App\Support\Files\AttachmentListing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The client's own copy of their client screens.
 *
 * Same shell and same pages as the operator sees, and deliberately not the same
 * queries. Sharing the presentation is what makes the two surfaces feel like
 * one application; sharing the reads is how an external user ends up looking at
 * a draft invoice or an unapproved time entry, so every method here goes
 * through an adapter that is fail-closed on the tenant, the company, the
 * operator's visibility decision and the record's lifecycle - and narrowed
 * again for a project-scoped portal user.
 *
 * Read-only throughout, with one exception: accepting a proposal, which is the
 * client's own act and lives on its own page rather than inline beside
 * everything else.
 */
class ClientPortalController extends Controller
{
    public function __construct(
        private readonly PortalAccess $portalAccess,
        private readonly PortalInvoiceQuery $invoices,
    ) {}

    /**
     * Client Home, for the client.
     *
     * What this replaced loaded the company's entire visible record - projects,
     * tasks, time, proposals, agreements, invoices and attachments - and drew a
     * card for each. It grew with the engagement, so the longer someone had been
     * a client the less their own screen told them. This is bounded, and every
     * section links to the module holding the rest.
     */
    public function show(Request $request, ClientCompany $clientCompany, PortalClientHomeQuery $home): Response
    {
        Gate::authorize('viewPortal', $clientCompany);

        return Inertia::render('clients/home', $home->for($clientCompany, $this->viewer($request))->toArray());
    }

    /**
     * One invoice, for the client it was sent to.
     *
     * The lines are the point: the list says what is owed and this says what
     * for, which is the question a client actually opens a portal to answer.
     *
     * Resolved through the same query the list uses, so an invoice the client
     * cannot see is not found rather than merely unlinked. Line rows are
     * narrowed to what belongs on an invoice a client is looking at; the
     * internal agreement and recurring-item keys the model already hides are
     * not reintroduced here by a hand-built array.
     */
    public function invoice(Request $request, ClientCompany $clientCompany, ClientInvoice $clientInvoice): Response
    {
        Gate::authorize('viewPortal', $clientCompany);

        $invoice = $this->invoices->visibleTo($clientCompany, $this->viewer($request))
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
            'home_href' => route('portal.show', $clientCompany, absolute: false),
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

    /**
     * The agreement this client is engaged under.
     *
     * Three refusals before it renders: a draft is a document nobody agreed to,
     * an agreement the operator has not made client-visible is not theirs to
     * read, and one scoped to a project belongs to whoever holds that project.
     * All three were conditions of the wall of cards this replaces; they are
     * asserted here because a detail route is reachable by id whether or not
     * anything linked to it.
     */
    public function agreement(
        Request $request,
        ClientCompany $clientCompany,
        ClientAgreement $clientAgreement,
    ): Response {
        Gate::authorize('viewPortal', $clientCompany);

        abort_unless(
            (int) $clientAgreement->workspace_id === (int) $clientCompany->workspace_id
                && (int) $clientAgreement->client_company_id === (int) $clientCompany->id
                && $clientAgreement->is_visible_to_client
                && $clientAgreement->status !== 'draft'
                && $this->withinScope($clientCompany, $request, $clientAgreement->client_project_id),
            404,
        );

        $items = $clientAgreement->recurringItems()
            ->where('workspace_id', $clientCompany->workspace_id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return Inertia::render('clients/agreement', [
            'company' => [
                'id' => $clientCompany->public_id,
                'name' => $clientCompany->name,
            ],
            'home_href' => route('portal.show', $clientCompany, absolute: false),
            'audience' => 'client',
            // Nothing to act on: correcting the terms and managing the files is
            // the operator's, and holding the client's own login is not
            // authority over the agreement. Sent as nulls rather than omitted
            // so both audiences share one payload shape.
            'actions' => [
                'update' => null,
                'upload_file' => null,
            ],
            // The files themselves are the client's to read - a countersigned
            // copy of their own agreement is the obvious one. `false` withholds
            // the removal URL; `AttachmentController` re-checks this agreement's
            // visibility, status and project scope on the way to each download.
            'files' => AttachmentListing::for(
                $clientCompany->workspace,
                'agreement',
                (string) $clientAgreement->public_id,
                false,
            ),
            'agreement' => AgreementTermsPayload::for(
                $clientAgreement,
                // The project an agreement is scoped to is named only to a
                // reader who holds it, and a client reaching this one already
                // does - so there is nothing here they could not otherwise see.
                null,
            ) + [
                // Internal engine behaviour: what the biller does when a term
                // is unstated. Sent as null rather than omitted so both
                // audiences share one payload shape, and the page renders none
                // of it for a client.
                'rollover_policy' => null,
                'catch_up_threshold_minutes' => null,
                'first_cycle_proration' => null,
                'bill_overage_interim' => null,
                'activated_at' => null,
                'terminated_at' => null,
                'signer_name' => $clientAgreement->signer_name,
                'signer_title' => $clientAgreement->signer_title,
                // The stored terms the operator's edit form writes back. A
                // client neither edits nor reads them: the derived per-period
                // figures above are what their agreement says, and these are
                // the two columns it is computed from.
                'retainer_minutes' => null,
                'retainer_amount' => null,
                'period_retainer_minutes' => null,
                'period_retainer_amount' => null,
                'agreement_text' => null,
                'is_visible_to_client' => null,
            ],
            'recurring_items' => $items->map(fn ($item): array => [
                'id' => $item->public_id,
                'description' => $item->description,
                'cadence' => $item->cadence,
                'quantity' => $item->quantity === null ? null : (float) $item->quantity,
                'amount' => $item->amount === null ? null : (int) $item->amount,
                'currency' => $item->currency,
                'effective_on' => $item->effective_on?->toDateString(),
                'expires_on' => $item->expires_on?->toDateString(),
                'is_active' => (bool) $item->is_active,
            ])->values()->all(),
        ]);
    }

    /**
     * One proposal, and the decision on it.
     *
     * The acceptance form used to sit on the portal's home screen among cards
     * for everything else the client had. Signing your name is a decision, and
     * a decision offered beside ten other things is one taken without reading
     * it - so it has its own page, reached from one line on Home saying
     * something is waiting.
     */
    public function proposal(
        Request $request,
        ClientCompany $clientCompany,
        ClientProposal $clientProposal,
    ): Response {
        Gate::authorize('viewPortal', $clientCompany);

        abort_unless(
            (int) $clientProposal->workspace_id === (int) $clientCompany->workspace_id
                && (int) $clientProposal->client_company_id === (int) $clientCompany->id
                && $clientProposal->is_visible_to_client
                && in_array($clientProposal->status, ['sent', 'accepted'], true)
                && $this->withinScope($clientCompany, $request, $clientProposal->client_project_id),
            404,
        );

        $items = $clientProposal->items()
            ->where('workspace_id', $clientCompany->workspace_id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $clientProposal->setRelation('items', $items);

        return Inertia::render('clients/proposal', [
            'company' => [
                'id' => $clientCompany->public_id,
                'name' => $clientCompany->name,
            ],
            'home_href' => route('portal.show', $clientCompany, absolute: false),
            'proposal' => [
                'id' => $clientProposal->public_id,
                'title' => $clientProposal->title,
                'summary' => $clientProposal->summary,
                'terms' => $clientProposal->terms,
                'status' => $clientProposal->status,
                'currency' => $clientProposal->currency,
                'valid_until' => $clientProposal->valid_until?->toDateString(),
                'sent_at' => $clientProposal->sent_at?->toISOString(),
                'accepted_at' => $clientProposal->accepted_at?->toISOString(),
                'total_amount' => $clientProposal->totalAmount(),
            ],
            'items' => $items->map(fn (ClientProposalItem $item): array => [
                'id' => $item->public_id,
                'description' => $item->description,
                // A string rather than a float: quantities are decimals in the
                // column, and rendering one through a float is how rounding
                // reaches a signature.
                'quantity' => (string) $item->quantity,
                'unit_amount' => (int) $item->unit_amount,
                'cadence' => $item->cadence,
            ])->values()->all(),
            // Offered only for a proposal still awaiting an answer. The action
            // behind it authorizes independently, because a form nobody
            // rendered is not an authorization check.
            'accept_href' => $clientProposal->status === 'sent'
                ? route('svc.engagement.proposals.accept', [$clientCompany, $clientProposal], absolute: false)
                : null,
        ]);
    }

    /**
     * Every invoice this client may see.
     *
     * Client Home carries the latest one and links here. Both resolve through
     * the same query, so the list cannot show a row the detail refuses - nor
     * refuse one the detail would serve.
     */
    public function invoices(Request $request, ClientCompany $clientCompany): Response
    {
        Gate::authorize('viewPortal', $clientCompany);

        $invoices = $this->invoices->visibleTo($clientCompany, $this->viewer($request))
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('clients/invoices', [
            'company' => [
                'id' => $clientCompany->public_id,
                'name' => $clientCompany->name,
            ],
            'invoice_base_href' => route('portal.invoices', $clientCompany, absolute: false),
            'invoices' => $invoices->map(fn (ClientInvoice $invoice): array => [
                'id' => $invoice->public_id,
                'invoice_number' => $invoice->invoice_number,
                'status' => $invoice->status,
                'currency' => $invoice->currency,
                'issue_date' => $invoice->issue_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'total_amount' => (int) $invoice->total_amount,
                'paid_amount' => (int) $invoice->paid_amount,
                'balance_amount' => (int) $invoice->balance_amount,
            ])->values()->all(),
        ]);
    }

    /**
     * The work done, as the client may read it.
     *
     * Its own screen rather than the operator's time sheet, and deliberately.
     * That sheet is a working tool - it logs, approves, and shows how much of a
     * retainer is left; this is a statement of work done. Sharing one component
     * would mean either handing a client the capacity strip or taking the
     * operator's tool away.
     *
     * Three conditions, each load-bearing. Approval is the gate, not visibility:
     * an entry is created as a draft, so filtering on the visibility flag alone
     * showed clients work nobody had approved - and work later rejected. And a
     * row with no client-safe description was never cleared for the client at
     * all, whatever the flag says; the internal note is never the fallback.
     */
    public function time(Request $request, ClientCompany $clientCompany): Response
    {
        Gate::authorize('viewPortal', $clientCompany);

        $visibleProjectIds = $this->portalAccess->visibleProjectIds($clientCompany, $this->viewer($request));

        $projects = $this->visibleProjects($clientCompany, $visibleProjectIds);
        $projectNames = $projects->pluck('name', 'id');

        $entries = ClientTimeEntry::query()
            ->where('workspace_id', $clientCompany->workspace_id)
            ->where('client_company_id', $clientCompany->id)
            ->where('is_visible_to_client', true)
            ->approved()
            ->whereNotNull('client_visible_description')
            ->whereIn('client_project_id', $projects->pluck('id')->all())
            ->orderByDesc('worked_on')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('portal/time', [
            'company' => [
                'id' => $clientCompany->public_id,
                'name' => $clientCompany->name,
            ],
            'entries' => $entries->map(fn (ClientTimeEntry $entry): array => [
                'id' => $entry->public_id,
                'worked_on' => $entry->worked_on->toDateString(),
                'project' => $projectNames[$entry->client_project_id] ?? null,
                // Read-only. Rates, costs and internal descriptions never cross
                // this line; no client-reachable route writes time.
                'description' => $entry->client_visible_description,
                'minutes' => (int) $entry->minutes,
            ])->values()->all(),
        ]);
    }

    /** The tasks this client may see, with the project as a filter. */
    public function tasks(Request $request, ClientCompany $clientCompany): Response
    {
        Gate::authorize('viewPortal', $clientCompany);

        $visibleProjectIds = $this->portalAccess->visibleProjectIds($clientCompany, $this->viewer($request));
        $projects = $this->visibleProjects($clientCompany, $visibleProjectIds);

        // A stale or unreachable project falls back to every visible one,
        // rather than to an empty list that reads as "there are no tasks".
        $requested = $request->query('project');
        $selected = is_string($requested) && $requested !== ''
            ? $projects->firstWhere('public_id', $requested)
            : null;

        $scope = $selected === null ? $projects : collect([$selected]);
        $projectNames = $projects->pluck('name', 'id');

        $tasks = ClientTask::query()
            ->where('workspace_id', $clientCompany->workspace_id)
            ->whereIn('client_project_id', $scope->pluck('id')->all())
            ->where('is_visible_to_client', true)
            ->orderBy('status')
            ->orderBy('title')
            ->get();

        return Inertia::render('clients/tasks', [
            'company' => [
                'id' => $clientCompany->public_id,
                'name' => $clientCompany->name,
            ],
            // The "client sees" column is a statement about disclosure, which
            // is meaningless on the copy of this screen the client is reading.
            'audience' => 'client',
            'filters' => ['project_id' => $selected?->public_id],
            'projects' => $projects->map(fn (ClientProject $project): array => [
                'id' => $project->public_id,
                'name' => $project->name,
            ])->values()->all(),
            'tasks' => $tasks->map(fn (ClientTask $task): array => [
                'id' => $task->public_id,
                'title' => $task->title,
                'status' => $task->status,
                'project' => $projectNames[$task->client_project_id] ?? null,
                'is_visible_to_client' => true,
                'completed_at' => $task->completed_at?->toDateString(),
            ])->values()->all(),
        ]);
    }

    /**
     * The projects behind every module here.
     *
     * Client-visible, and narrowed again for a project-scoped user. One place,
     * because "which projects" is the question every one of these screens is
     * really asking and three copies of it is three chances to forget a
     * condition.
     *
     * @param  list<int>|null  $visibleProjectIds  null is the whole company
     * @return Collection<int, ClientProject>
     */
    private function visibleProjects(ClientCompany $company, ?array $visibleProjectIds): Collection
    {
        return ClientProject::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->where('is_visible_to_client', true)
            ->when($visibleProjectIds !== null, fn (Builder $query): Builder => $query
                ->whereIn('id', $visibleProjectIds ?? []))
            ->orderBy('name')
            ->get();
    }

    /** The portal is a web surface; an agent principal never reaches it. */
    private function viewer(Request $request): ?User
    {
        $viewer = $request->user();

        return $viewer instanceof User ? $viewer : null;
    }

    /**
     * Whether a record scoped to one project is this viewer's to read.
     *
     * A record naming no project belongs to the whole company and passes. One
     * naming a project passes only for a viewer who holds it - proposals and
     * agreements carry rates, retainers and terms, and a user narrowed to one
     * project must not read another's, nor accept a proposal that was never
     * theirs.
     */
    private function withinScope(ClientCompany $company, Request $request, ?int $projectId): bool
    {
        $visible = $this->portalAccess->visibleProjectIds($company, $this->viewer($request));

        return $visible === null || $projectId === null || in_array($projectId, $visible, true);
    }
}
