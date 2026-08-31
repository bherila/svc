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
use App\Services\Billing\InvoiceDocumentService;
use App\Services\Billing\InvoiceEmailService;
use App\Services\Billing\InvoiceFromTimeService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Billing\StripePaymentIntentService;
use App\Services\WorkspaceAuthorization;
use Illuminate\Contracts\View\View;
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
    ) {}

    public function index(Request $request, Workspace $workspace): JsonResponse|View
    {
        $this->authorizeWorkspaceView($request, $workspace);
        $query = ClientInvoice::query()->where('workspace_id', $workspace->id)->with('clientCompany');
        if (! $workspace->memberships()->where('user_id', $request->user()->id)->exists()) {
            // Through AgentAccess so the membership's own workspace is checked too,
            // rather than restating half the condition here.
            $companyIds = $this->agentAccess->portalCompanyIdsIn($request->user(), $workspace);
            $query->whereIn('client_company_id', $companyIds)
                ->where('is_visible_to_client', true)
                ->whereIn('status', ['issued', 'partially_paid', 'paid']);
        }
        $invoices = $query->latest('id')->get();

        return $request->expectsJson() ? response()->json(['data' => $invoices]) : view('invoices.index', compact('workspace', 'invoices'));
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

    public function show(Request $request, Workspace $workspace, ClientInvoice $clientInvoice): JsonResponse|View
    {
        $this->authorizeInvoiceView($request, $workspace, $clientInvoice);
        $invoice = $clientInvoice->load(['lines', 'payments', 'clientCompany']);

        return $request->expectsJson() ? response()->json(['data' => $invoice]) : view('invoices.show', compact('invoice'));
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
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
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
