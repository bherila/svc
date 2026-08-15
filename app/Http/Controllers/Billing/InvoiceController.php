<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\CreateStripePaymentIntentRequest;
use App\Http\Requests\Billing\SendInvoiceRequest;
use App\Http\Requests\Billing\StoreInvoiceRequest;
use App\Http\Requests\Billing\StorePaymentRequest;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Services\Billing\InvoiceDocumentService;
use App\Services\Billing\InvoiceEmailService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Billing\StripePaymentIntentService;
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
    public function index(Request $request, Workspace $workspace): JsonResponse|View
    {
        $this->authorizeWorkspaceView($request, $workspace);
        $query = ClientInvoice::query()->where('workspace_id', $workspace->id)->with('clientCompany');
        if (! $workspace->memberships()->where('user_id', $request->user()->id)->exists()) {
            $companyIds = $request->user()->clientCompanies()->where('workspace_id', $workspace->id)->pluck('client_companies.id');
            $query->whereIn('client_company_id', $companyIds)
                ->where('is_visible_to_client', true)
                ->whereIn('status', ['issued', 'partially_paid', 'paid']);
        }
        $invoices = $query->latest('id')->get();

        return $request->expectsJson() ? response()->json(['data' => $invoices]) : view('invoices.index', compact('workspace', 'invoices'));
    }

    public function store(StoreInvoiceRequest $request, Workspace $workspace, ClientCompany $clientCompany, InvoiceLifecycleService $service): JsonResponse|RedirectResponse
    {
        Gate::authorize('manage', $workspace);
        abort_unless($clientCompany->workspace_id === $workspace->id, 404);
        $invoice = $service->createDraft($workspace, $clientCompany, $request->validated(), $request->validated('lines'));

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
        abort_unless($clientInvoice->workspace_id === $workspace->id, 404);
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
        $this->authorizeInvoiceView($request, $workspace, $clientInvoice);
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
        abort_unless($clientInvoice->workspace_id === $workspace->id, 404);
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
        abort_unless($request->user()->clientCompanies()->where('workspace_id', $workspace->id)->exists(), 403);
    }

    private function authorizeInvoiceView(Request $request, Workspace $workspace, ClientInvoice $invoice): void
    {
        abort_unless($invoice->workspace_id === $workspace->id, 404);
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
