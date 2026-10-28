<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\CorrectIssuedInvoiceRequest;
use App\Http\Requests\Billing\CorrectPaymentDateRequest;
use App\Http\Requests\Billing\CreateStripePaymentIntentRequest;
use App\Http\Requests\Billing\SendInvoiceRequest;
use App\Http\Requests\Billing\StoreInvoiceRequest;
use App\Http\Requests\Billing\StorePaymentRequest;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Authorization\BillingRecordAccess;
use App\Services\Authorization\PortalInvoiceQuery;
use App\Services\Billing\InvoiceCorrectionReadService;
use App\Services\Billing\InvoiceCorrectionService;
use App\Services\Billing\InvoiceDocumentService;
use App\Services\Billing\InvoiceEmailService;
use App\Services\Billing\InvoiceFromTimeService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Billing\RecordReceivedPayment;
use App\Services\Billing\StripePaymentIntentService;
use App\Services\WorkspaceAuthorization;
use App\Support\Billing\InvoiceEmailDraft;
use App\Support\Billing\InvoiceLineDetail;
use App\Support\Billing\InvoiceStatus;
use App\Support\Billing\ReceivedPaymentData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\HeaderUtils;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly WorkspaceAuthorization $workspaceAuthorization,
        private readonly AgentAccess $agentAccess,
        private readonly BillingRecordAccess $billingAccess,
        private readonly PortalInvoiceQuery $portalInvoices,
    ) {}

    public function index(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->authorizeWorkspaceView($request, $workspace);

        // The workspace-wide screen is gone: an invoice lives inside one
        // client, and a copy of the list with no client named around it was a
        // second way to reach the same rows. The URL still resolves - through
        // the same entry point as every other way into a workspace - and lands
        // on the Invoices tab of the client this operator was last in. A
        // program reads invoices through invoices.list on the versioned API,
        // not through this page (#454).
        return redirect()->route('workspaces.enter', [
            'workspace' => $workspace,
            'module' => 'invoices',
        ]);
    }

    public function store(StoreInvoiceRequest $request, Workspace $workspace, ClientCompany $clientCompany, InvoiceFromTimeService $fromTime): RedirectResponse
    {
        Gate::authorize('manage', $workspace);
        $this->workspaceAuthorization->assertOwnedBy($workspace, $clientCompany);

        $attributes = $request->validated();
        $timeEntryIds = array_values($attributes['time_entry_ids'] ?? []);
        $lines = $attributes['lines'] ?? [];
        unset($attributes['time_entry_ids'], $attributes['lines']);

        // Selecting time routes through the service that owns allocation: it locks the
        // entries, refuses ones already billed, and records the link. The plain
        // lifecycle path stays for invoices built only from manual lines.
        // Always through the service that normalises manual lines, so a line's
        // project association does not depend on whether time happens to be
        // selected alongside it.
        $fromTime->create($workspace, $clientCompany, $attributes, $timeEntryIds, $lines);

        return redirect()->back()->with('status', 'Invoice drafted.');
    }

    /**
     * One invoice, by its workspace-wide URL.
     *
     * It goes to the invoice's own screen inside its client, where the chrome
     * says whose invoice it is and the lifecycle actions are - rather than to a
     * standalone Blade page that had neither, reachable from a list that no
     * longer exists. A program reads it through invoices.get (#454).
     */
    public function show(Request $request, Workspace $workspace, ClientInvoice $clientInvoice): RedirectResponse
    {
        $this->authorizeInvoiceView($request, $workspace, $clientInvoice);

        $company = $clientInvoice->clientCompany;

        // Lineage rather than a constrained relation: a row migrated in from
        // before the composite tenant keys can name a company of another
        // tenant, and that is not a client screen to send anyone to.
        abort_if(
            $company === null || (int) $company->workspace_id !== (int) $workspace->id,
            404,
        );

        return redirect()->route('clients.invoice', [$workspace, $company, $clientInvoice]);
    }

    public function addTime(Request $request, Workspace $workspace, string $clientInvoice, InvoiceFromTimeService $service): RedirectResponse
    {
        Gate::authorize('manage', $workspace);
        $data = $request->validate([
            'expected_version' => ['required', 'string', 'max:100'],
            'time_entry_ids' => ['required', 'array', 'min:1', 'max:100'],
            'time_entry_ids.*' => ['required', 'uuid', 'distinct'],
        ]);
        $invoice = ClientInvoice::query()->where('workspace_id', $workspace->id)
            ->where('public_id', $clientInvoice)->firstOrFail();
        $updated = $service->addTime($invoice, $workspace, $data['expected_version'], array_values($data['time_entry_ids']));

        return redirect()->route('clients.invoice', [$workspace, $updated->clientCompany, $updated])->with('status', 'Time added to draft invoice.');
    }

    public function issue(Workspace $workspace, ClientInvoice $clientInvoice, InvoiceLifecycleService $service): RedirectResponse
    {
        Gate::authorize('manage', $workspace);
        $this->workspaceAuthorization->assertOwnedBy($workspace, $clientInvoice);
        $service->issue($clientInvoice, $workspace);

        return $this->done('Invoice issued.');
    }

    /**
     * Void an invoice, saying why (#454): the reason is required here as it is
     * by `invoices.void`, and kept on the invoice.
     */
    public function void(Request $request, Workspace $workspace, ClientInvoice $clientInvoice, InvoiceLifecycleService $service): RedirectResponse
    {
        Gate::authorize('manage', $workspace);
        $service->assertTenant($workspace, $clientInvoice);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:'.ClientInvoice::VOID_REASON_MAX_LENGTH]])['reason'];
        $service->void($clientInvoice, $workspace, trim((string) $reason));

        return $this->done('Invoice voided.');
    }

    public function correct(
        CorrectIssuedInvoiceRequest $request,
        Workspace $workspace,
        ClientInvoice $clientInvoice,
        InvoiceCorrectionService $service,
    ): RedirectResponse {
        Gate::authorize('manage', $workspace);
        $this->workspaceAuthorization->assertOwnedBy($workspace, $clientInvoice);
        $data = $request->validated();
        $service->correct(
            $clientInvoice,
            $workspace,
            (int) $data['expected_revision'],
            array_key_exists('due_date', $data),
            isset($data['due_date']) ? (string) $data['due_date'] : null,
            (string) $data['reason'],
            $data['lines'],
        );

        return $this->done('Invoice corrected and automatic delivery held for review.');
    }

    public function correction(Request $request, Workspace $workspace, ClientInvoice $clientInvoice, string $correction, InvoiceCorrectionReadService $evidence): JsonResponse
    {
        Gate::authorize('manage', $workspace);
        $this->workspaceAuthorization->assertOwnedBy($workspace, $clientInvoice);

        return response()->json(['data' => $evidence->get($workspace, $clientInvoice, $correction)]);
    }

    public function holdAutomatic(Workspace $workspace, ClientInvoice $clientInvoice, InvoiceCorrectionService $service): RedirectResponse
    {
        Gate::authorize('manage', $workspace);
        $service->hold($clientInvoice, $workspace);

        return $this->done('Automatic invoice delivery held.');
    }

    public function releaseAutomatic(Workspace $workspace, ClientInvoice $clientInvoice, InvoiceCorrectionService $service): RedirectResponse
    {
        Gate::authorize('manage', $workspace);
        $service->release($clientInvoice, $workspace);

        return $this->done('Automatic invoice delivery released.');
    }

    public function payment(StorePaymentRequest $request, Workspace $workspace, ClientInvoice $clientInvoice, RecordReceivedPayment $service): RedirectResponse
    {
        Gate::authorize('manage', $workspace);
        $facts = ReceivedPaymentData::from($request->validated(), requireDate: false, includeBookkeeping: true, acceptsBodyKey: true);
        $service->record($workspace, $clientInvoice, $facts, $facts->idempotencyKey ?? $request->header('Idempotency-Key'));

        return $this->done('Payment recorded.');
    }

    /**
     * Correct the day an already-recorded payment arrived on.
     *
     * The narrowest write on this controller: one column, on one payment, of
     * one invoice, in one workspace.
     *
     * **Both records are resolved rather than bound.** Route-model binding
     * resolves a parameter by its route key before this method runs, and both
     * of these bind by a `public_id` that is unique across every workspace - so
     * a request naming another tenant's invoice or payment selected and
     * materialised that row with no workspace predicate, and a check made here
     * could refuse the write but could not un-read it. The scoped lookups below
     * are the same shape `ExpenseController` already uses for its own child
     * writes, which take the id as a string for exactly this reason.
     *
     * They also *are* the tenancy check, which is why there is no longer an
     * `assertTenant()` or an `abort_unless()` after them: an invoice that is
     * not this workspace's and a payment that is not that invoice's are both
     * simply not found, and a row nobody can name is a better answer than a row
     * described and then refused.
     *
     * Everything else about the payment is out of reach by construction:
     * {@see CorrectPaymentDateRequest} validates one field and the reason, and
     * {@see InvoiceLifecycleService::correctPayment()} is handed only that
     * field.
     */
    public function correctPaymentDate(
        CorrectPaymentDateRequest $request,
        Workspace $workspace,
        string $clientInvoice,
        string $clientInvoicePayment,
        InvoiceLifecycleService $service,
    ): RedirectResponse {
        Gate::authorize('manage', $workspace);

        $invoice = ClientInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->where('public_id', $clientInvoice)
            ->firstOrFail();

        $existing = ClientInvoicePayment::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_invoice_id', $invoice->id)
            ->where('public_id', $clientInvoicePayment)
            ->firstOrFail();

        // The general correction with one field named, so the screen records
        // the same audited `invoice.payment_corrected` - before, after and
        // reason - that `payments.correct` and the console command do.
        $service->correctPayment(
            $existing,
            ['received_on' => (string) $request->validated('received_on')],
            (string) $request->validated('reason'),
            null,
            $workspace,
        );

        return $this->done('Payment date corrected.');
    }

    public function stripePaymentIntent(CreateStripePaymentIntentRequest $request, Workspace $workspace, ClientInvoice $clientInvoice, StripePaymentIntentService $service): JsonResponse
    {
        // Paying is not reading. This route creates a real Stripe intent and
        // records a pending payment that reserves the remaining balance, so an
        // abandoned or unauthorised one blocks a genuine payment - and it was
        // gated on the same check as opening the invoice, which made any
        // workspace member a payer by side effect.
        $this->authorizeInvoicePayment($request, $workspace, $clientInvoice);
        $data = $request->validated();
        $idempotencyKey = $data['idempotency_key'] ?? $request->header('Idempotency-Key');
        abort_unless(is_string($idempotencyKey) && trim($idempotencyKey) !== '', 422, 'An idempotency key is required.');
        $result = $service->create(
            $clientInvoice,
            $workspace,
            $data['payment_method_id'] ?? null,
            $idempotencyKey,
        );

        return response()->json([
            'payment_intent_id' => $result['payment_intent_id'],
            'client_secret' => $result['client_secret'],
            'payment' => $result['payment'],
        ], 201);
    }

    /**
     * Send the invoice, and say what happened.
     *
     * The send is synchronous and the message reports the outcome, because the
     * previous version could only ever promise to try: it dispatched a queued
     * job onto the database driver, nothing runs a worker, and the screen said
     * "Invoice delivery queued." while the row sat unread. An operator has no
     * way to tell a hopeful message from a delivered one.
     *
     * A refusal from the mailer is a `DomainException`, which the application
     * renders as a 422 carrying its own message - so the compose dialog shows
     * the reason where it was asked for rather than a generic error page.
     */
    public function send(SendInvoiceRequest $request, Workspace $workspace, ClientInvoice $clientInvoice, InvoiceEmailService $service): RedirectResponse
    {
        Gate::authorize('manage', $workspace);
        $this->workspaceAuthorization->assertOwnedBy($workspace, $clientInvoice);

        $user = $request->user();
        $recipients = $request->validated('recipients')
            ?? array_filter([$clientInvoice->clientCompany?->billing_email]);

        $delivery = $service->send(
            $clientInvoice,
            InvoiceEmailDraft::composed(
                array_values($recipients),
                $user instanceof User ? (string) $user->email : null,
                $request->boolean('bcc_self'),
                $request->validated('subject'),
                $service->defaultSubject($clientInvoice),
                $request->validated('message'),
            ),
            $workspace,
            $request->validated('idempotency_key'),
        );

        $sent = $delivery->status === 'sent';
        $message = $sent
            ? 'Invoice sent to '.implode(', ', $delivery->recipients).'.'
            : 'Invoice delivery registered and will send shortly.';

        return $this->done($message);
    }

    public function pdf(Request $request, Workspace $workspace, ClientInvoice $clientInvoice, InvoiceDocumentService $documents): Response
    {
        // The same route serves both populations, and the appendix behind this
        // document is not the same document for both: an operator's lists every
        // entry with its internal description, a client's lists only what was
        // written for them to read. So the check that admits the reader also
        // says which appendix they get.
        $audience = $this->authorizeInvoiceView($request, $workspace, $clientInvoice);
        $pdf = $documents->pdf($clientInvoice, $audience);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            // Inline, because the control that leads here says "View PDF".
            // As an attachment the browser downloaded it instead of opening
            // it, so reading one invoice left a file behind in Downloads and
            // the reader had to leave the application to look at it.
            //
            // Safe to inline here in a way an uploaded attachment is not: this
            // body is generated by `InvoiceDocumentService` from our own
            // template, so nothing a client supplied is being handed to the
            // browser as a document to execute in this origin. The filename is
            // still stated, so "save as" from the viewer keeps a useful name.
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_INLINE,
                $documents->filename($clientInvoice),
            ),
        ]);
    }

    private function authorizeWorkspaceView(Request $request, Workspace $workspace): void
    {
        if (Gate::forUser($request->user())->allows('view', $workspace)) {
            return;
        }
        abort_unless($this->agentAccess->isWorkspaceClient($request->user(), $workspace), 403);
    }

    /**
     * Who may start a payment against this invoice.
     *
     * Strictly narrower than viewing it. Reading an invoice is something a
     * member of the team does; paying one is something the client does, and
     * conflating them let anyone who could open an invoice reserve its balance
     * with an intent nobody asked for.
     *
     * So: a portal user of the company, admitted by the same visibility and
     * status rules the portal itself applies. Internal staff are refused -
     * an operator recording a payment has other routes, and none of them
     * should be a side effect of being able to look.
     */
    private function authorizeInvoicePayment(Request $request, Workspace $workspace, ClientInvoice $invoice): void
    {
        $this->workspaceAuthorization->assertOwnedBy($workspace, $invoice);

        $user = $request->user();

        abort_unless(
            $user instanceof User
                && $invoice->is_visible_to_client
                && in_array($invoice->status, InvoiceStatus::collectible(), true)
                && $invoice->clientCompany?->portalUsers()->whereKey($user->id)->exists() === true,
            403,
        );
    }

    /**
     * Who may read this invoice, and as which audience.
     *
     * @return InvoiceLineDetail::OPERATOR|InvoiceLineDetail::CLIENT
     */
    private function authorizeInvoiceView(Request $request, Workspace $workspace, ClientInvoice $invoice): string
    {
        $this->workspaceAuthorization->assertOwnedBy($workspace, $invoice);

        if (Gate::forUser($request->user())->allows('view', $workspace)) {
            // Membership admits them to the workspace, not to every client in
            // it (#157). Without this the list narrows and the direct routes do
            // not, so a scoped member reads any client's invoice - and its PDF,
            // which is the same disclosure with a filename - by pasting an id.
            // Reaching the client is not reaching this invoice. A member
            // granted one project of a client must not read an invoice for
            // work on another - see `BillingRecordAccess` for why every
            // attributed project has to be reachable rather than any.
            $user = $request->user();

            if ($user instanceof User) {
                abort_unless($this->billingAccess->canViewInvoice($user, $workspace, $invoice), 404);
            }

            return InvoiceLineDetail::OPERATOR;
        }
        abort_unless(
            $invoice->is_visible_to_client
                && in_array($invoice->status, InvoiceStatus::charged(), true)
                && $invoice->clientCompany->portalUsers()->whereKey($request->user()->id)->exists(),
            403,
        );
        // And the same project rule the portal list and screen apply: a member
        // granted some of a client's projects reads only invoices whose every
        // attributed project is granted. Membership of the company alone would
        // itemise another project's entries - project, date and hours - in
        // the appendix of a document the portal itself withholds.
        $viewer = $request->user();
        abort_unless(
            $viewer instanceof User
                && $this->portalInvoices->visibleTo($invoice->clientCompany, $viewer)->whereKey($invoice->getKey())->exists(),
            404,
        );

        return InvoiceLineDetail::CLIENT;
    }

    /**
     * Every write here answers the page that made it, and only that page.
     *
     * There used to be a JSON branch on each, keyed on the request asking for JSON, that
     * serialized the bare model - a third shape beside the Inertia props and the
     * versioned API's presenters, with internal columns in it, that nothing in
     * the browser read (#454). A program writes through `/api/v1`.
     */
    private function done(string $message): RedirectResponse
    {
        return redirect()->back()->with('status', $message);
    }
}
