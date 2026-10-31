<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\CreateStripePaymentIntentRequest;
use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Authorization\BillingRecordAccess;
use App\Services\Authorization\PortalInvoiceQuery;
use App\Services\Billing\InvoiceCorrectionReadService;
use App\Services\Billing\InvoiceDocumentService;
use App\Services\Billing\StripePaymentIntentService;
use App\Services\WorkspaceAuthorization;
use App\Support\Billing\InvoiceLineDetail;
use App\Support\Billing\InvoiceStatus;
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

    public function correction(Request $request, Workspace $workspace, ClientInvoice $clientInvoice, string $correction, InvoiceCorrectionReadService $evidence): JsonResponse
    {
        Gate::authorize('manage', $workspace);
        $this->workspaceAuthorization->assertOwnedBy($workspace, $clientInvoice);

        return response()->json(['data' => $evidence->get($workspace, $clientInvoice, $correction)]);
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
}
