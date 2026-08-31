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
use App\Services\Authorization\ProjectAccess;
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
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly WorkspaceAuthorization $workspaceAuthorization,
        private readonly AgentAccess $agentAccess,
        private readonly ProjectAccess $projectAccess,
    ) {}

    public function index(Request $request, Workspace $workspace): JsonResponse|InertiaResponse
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
            $reachable = $this->projectAccess->reachableCompanyIds($user, $workspace);

            if ($reachable !== null) {
                $query->whereIn('client_company_id', $reachable);
            }
        }

        $invoices = $query->latest('id')->get();

        if ($request->expectsJson()) {
            return response()->json(['data' => $invoices]);
        }

        // The workspace-wide list, above any one client - so it renders with no
        // client chrome, the way the workspace time sheet does. Clicking an
        // invoice drops into that client's context, which is where an invoice
        // actually lives.
        return Inertia::render('invoices/index', [
            'workspace' => [
                'id' => $workspace->public_id,
                'name' => $workspace->name,
            ],
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
                'company' => [
                    'id' => $invoice->clientCompany?->public_id,
                    'name' => $invoice->clientCompany?->name,
                ],
                // Built here rather than in the page, because where a row leads
                // depends on who is asking. A member goes to the client-scoped
                // detail; a portal viewer would be refused there - that route
                // authorizes on workspace membership - so they get the route
                // that applies portal invoice authorization instead.
                'href' => $isMember
                    ? ($invoice->clientCompany === null
                        ? null
                        : route('clients.invoice', [
                            'workspace' => $workspace,
                            'clientCompany' => $invoice->clientCompany,
                            'clientInvoice' => $invoice,
                        ], false))
                    : route('svc.billing.invoices.show', [
                        'workspace' => $workspace,
                        'clientInvoice' => $invoice,
                    ], false),
            ])->values()->all(),
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

    private function authorizeInvoiceView(Request $request, Workspace $workspace, ClientInvoice $invoice): void
    {
        $this->workspaceAuthorization->assertOwnedBy($workspace, $invoice);

        if (Gate::forUser($request->user())->allows('view', $workspace)) {
            // Membership admits them to the workspace, not to every client in
            // it (#157). Without this the list narrows and the direct routes do
            // not, so a scoped member reads any client's invoice - and its PDF,
            // which is the same disclosure with a filename - by pasting an id.
            $user = $request->user();

            if ($user instanceof User) {
                $reachable = $this->projectAccess->reachableCompanyIds($user, $workspace);

                abort_unless(
                    $reachable === null
                        || in_array((int) $invoice->client_company_id, $reachable, true),
                    404,
                );
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
