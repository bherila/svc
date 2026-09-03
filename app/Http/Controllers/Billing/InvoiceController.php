<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\CreateStripePaymentIntentRequest;
use App\Http\Requests\Billing\SendInvoiceRequest;
use App\Http\Requests\Billing\StoreInvoiceRequest;
use App\Http\Requests\Billing\StorePaymentRequest;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Authorization\BillingRecordAccess;
use App\Services\Billing\InvoiceDocumentService;
use App\Services\Billing\InvoiceEmailService;
use App\Services\Billing\InvoiceFromTimeService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Billing\StripePaymentIntentService;
use App\Services\WorkspaceAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly WorkspaceAuthorization $workspaceAuthorization,
        private readonly AgentAccess $agentAccess,
        private readonly BillingRecordAccess $billingAccess,
    ) {}

    public function index(Request $request, Workspace $workspace): JsonResponse|RedirectResponse
    {
        $this->authorizeWorkspaceView($request, $workspace);
        // The company relation is constrained to this workspace. It is
        // unconstrained lineage, so a row migrated from before #113's composite
        // keys can name a company in another tenant - and serializing its name
        // here would be a cross-tenant disclosure the old Blade page never
        // made, on a screen whose whole job is listing everything.
        $query = ClientInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->with(['clientCompany' => fn ($relation) => $relation->where('workspace_id', $workspace->id)]);
        // One membership decision for the whole request. Asking twice let a
        // revocation between the two calls skip *both* narrowings and leave the
        // query bounded only by workspace - every invoice in it, for one
        // request.
        $isMember = $workspace->memberships()->where('user_id', $request->user()->id)->exists();

        if (! $isMember) {
            // Through AgentAccess so the membership's own workspace is checked too,
            // rather than restating half the condition here.
            $companyIds = $this->agentAccess->portalCompanyIdsIn($request->user(), $workspace);
            $query->whereIn('client_company_id', $companyIds)
                ->where('is_visible_to_client', true)
                ->whereIn('status', ['issued', 'partially_paid', 'paid']);
        }
        // A workspace member sees the clients they reach, on the same rule the
        // directory follows (#157). Portal users were narrowed above by the
        // company ids their access grants, so exactly one of these two branches
        // applies to any request.
        $user = $request->user();

        if ($isMember && $user instanceof User) {
            $query = $this->billingAccess->constrainInvoices($query, $user, $workspace);
        }

        $invoices = $query->latest('id')->get();

        if ($request->expectsJson()) {
            return response()->json(['data' => $invoices]);
        }

        // The workspace-wide screen is gone: an invoice lives inside one
        // client, and a copy of the list with no client named around it was a
        // second way to reach the same rows. The URL still resolves - through
        // the same entry point as every other way into a workspace - and lands
        // on the Invoices tab of the client this operator was last in. The JSON
        // branch above is untouched: an API caller asked for the whole
        // workspace and still gets it.
        return redirect()->route('workspaces.enter', [
            'workspace' => $workspace,
            'module' => 'invoices',
        ]);
    }

    public function store(StoreInvoiceRequest $request, Workspace $workspace, ClientCompany $clientCompany, InvoiceLifecycleService $service, InvoiceFromTimeService $fromTime): JsonResponse|RedirectResponse
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
        $invoice = $fromTime->create($workspace, $clientCompany, $attributes, $timeEntryIds, $lines);

        return $request->expectsJson()
            ? response()->json(['data' => $invoice], 201)
            : redirect()->back()->with('status', 'Invoice drafted.');
    }

    /**
     * One invoice, by its workspace-wide URL.
     *
     * The JSON is unchanged. The HTML now goes to the invoice's own screen
     * inside its client, where the chrome says whose invoice it is and the
     * lifecycle actions are - rather than to a standalone Blade page that had
     * neither, reachable from a list that no longer exists.
     */
    public function show(Request $request, Workspace $workspace, ClientInvoice $clientInvoice): JsonResponse|RedirectResponse
    {
        $this->authorizeInvoiceView($request, $workspace, $clientInvoice);

        if ($request->expectsJson()) {
            return response()->json(['data' => $clientInvoice->load(['lines', 'payments', 'clientCompany'])]);
        }

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

    public function issue(Request $request, Workspace $workspace, ClientInvoice $clientInvoice, InvoiceLifecycleService $service): JsonResponse|RedirectResponse
    {
        Gate::authorize('manage', $workspace);
        $this->workspaceAuthorization->assertOwnedBy($workspace, $clientInvoice);
        $invoice = $service->issue($clientInvoice, $workspace);

        return $this->mutationResponse($request, $invoice, 'Invoice issued.');
    }

    public function void(Request $request, Workspace $workspace, ClientInvoice $clientInvoice, InvoiceLifecycleService $service): JsonResponse|RedirectResponse
    {
        Gate::authorize('manage', $workspace);
        $service->assertTenant($workspace, $clientInvoice);
        $invoice = $service->void($clientInvoice, $workspace);

        return $this->mutationResponse($request, $invoice, 'Invoice voided.');
    }

    public function payment(StorePaymentRequest $request, Workspace $workspace, ClientInvoice $clientInvoice, InvoiceLifecycleService $service): JsonResponse|RedirectResponse
    {
        Gate::authorize('manage', $workspace);
        $service->assertTenant($workspace, $clientInvoice);
        $data = $request->validated();
        $data['idempotency_key'] ??= $request->header('Idempotency-Key');
        $payment = $service->applyPayment($clientInvoice, $data, $workspace);

        return $request->expectsJson()
            ? response()->json(['data' => $payment->load('invoice')], 201)
            : redirect()->back()->with('status', 'Payment recorded.');
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

    public function send(SendInvoiceRequest $request, Workspace $workspace, ClientInvoice $clientInvoice, InvoiceEmailService $service): JsonResponse|RedirectResponse
    {
        Gate::authorize('manage', $workspace);
        $this->workspaceAuthorization->assertOwnedBy($workspace, $clientInvoice);
        $recipients = $request->validated('recipients') ?? array_filter([$clientInvoice->clientCompany->billing_email]);
        $delivery = $service->queue($clientInvoice, $recipients, $workspace);

        return $request->expectsJson()
            ? response()->json(['data' => $delivery], 202)
            : redirect()->back()->with('status', 'Invoice delivery queued.');
    }

    public function pdf(Request $request, Workspace $workspace, ClientInvoice $clientInvoice, InvoiceDocumentService $documents): Response
    {
        $this->authorizeInvoiceView($request, $workspace, $clientInvoice);
        $pdf = $documents->pdf($clientInvoice);

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
                'invoice-'.(Str::slug($clientInvoice->invoice_number) ?: $clientInvoice->public_id).'.pdf',
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
                && in_array($invoice->status, ['issued', 'partially_paid'], true)
                && $invoice->clientCompany?->portalUsers()->whereKey($user->id)->exists() === true,
            403,
        );
    }

    private function authorizeInvoiceView(Request $request, Workspace $workspace, ClientInvoice $invoice): void
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

            return;
        }
        abort_unless(
            $invoice->is_visible_to_client
                && in_array($invoice->status, ['issued', 'partially_paid', 'paid'], true)
                && $invoice->clientCompany->portalUsers()->whereKey($request->user()->id)->exists(),
            403,
        );
    }

    private function mutationResponse(Request $request, ClientInvoice $invoice, string $message): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['data' => $invoice])
            : redirect()->back()->with('status', $message);
    }
}
