<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentInvoiceOperations;
use App\Services\AgentApi\AgentInvoiceReviewReadService;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Services\AgentApi\AgentReadService;
use App\Services\Authorization\AgentAccess;
use App\Services\Billing\InvoiceDocumentService;
use App\Support\AgentApi\Presenters\AgentInvoicePresenter;
use App\Support\Billing\InvoiceLineDetail;
use App\Support\WorkspaceClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\HeaderUtils;

final class AgentInvoiceOperationsController extends Controller
{
    public function __construct(private readonly AgentInvoiceOperations $operations, private readonly AgentMutationContextFactory $contexts, private readonly AgentInvoicePresenter $presenter) {}

    public function hold(Request $request, Workspace $workspace, string $invoice): JsonResponse
    {
        return $this->setHold($request, $workspace, $invoice, true);
    }

    public function release(Request $request, Workspace $workspace, string $invoice): JsonResponse
    {
        return $this->setHold($request, $workspace, $invoice, false);
    }

    private function setHold(Request $request, Workspace $workspace, string $invoice, bool $hold): JsonResponse
    {
        $context = $this->contexts->from($request);
        $record = $this->operations->hold($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey, $invoice, $request->all(), $hold);

        return response()->json(['data' => $this->presenter->mutation($workspace, $record)]);
    }

    public function addTime(Request $request, Workspace $workspace, string $invoice): JsonResponse
    {
        $context = $this->contexts->from($request);
        $record = $this->operations->addTime($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey, $invoice, $request->all());

        return response()->json(['data' => $this->presenter->mutation($workspace, $record)]);
    }

    public function generate(Request $request, Workspace $workspace, string $agreement): JsonResponse
    {
        $context = $this->contexts->from($request);
        $record = $this->operations->generate($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey, $agreement, $request->all());

        return response()->json(['data' => $this->presenter->mutation($workspace, $record)], 201);
    }

    public function audit(Request $request, Workspace $workspace, AgentInvoiceReviewReadService $reviews): JsonResponse
    {
        return response()->json(['data' => $reviews->staleAndMissing($this->principal($request), $workspace)->toArray()]);
    }

    public function pdfLink(Request $request, Workspace $workspace, string $invoice, AgentReadService $reads, WorkspaceClock $clock): JsonResponse
    {
        $reads->invoice($this->principal($request), $workspace, $invoice);
        $expires = $clock->now($workspace)->addMinutes(5);

        return response()->json(['data' => ['url' => rtrim((string) config('app.url'), '/').URL::temporarySignedRoute('agent-api.v1.invoices.download_pdf', $expires, ['workspace' => $workspace->public_id, 'invoice' => $invoice], absolute: false), 'expires_at' => $expires->toISOString()]]);
    }

    public function pdf(Request $request, Workspace $workspace, string $invoice, AgentReadService $reads, AgentAccess $access, InvoiceDocumentService $documents): Response
    {
        if ($request->hasAny(['signature', 'expires'])) {
            abort_unless($request->hasValidSignature(absolute: false), 403, 'The invoice download URL has expired or is invalid.');
        }
        $principal = $this->principal($request);
        $reads->invoice($principal, $workspace, $invoice);
        $record = ClientInvoice::query()->where('workspace_id', $workspace->id)->where('public_id', $invoice)->with(['clientCompany' => fn ($companies) => $companies->where('workspace_id', $workspace->id), 'workspace'])->firstOrFail();
        $audience = $access->isWorkspaceManager($principal, $workspace) ? InvoiceLineDetail::OPERATOR : InvoiceLineDetail::CLIENT;

        return response($documents->pdf($record, $audience), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $documents->filename($record))]);
    }

    private function principal(Request $request): User|AgentPrincipal
    {
        $principal = $request->user();
        abort_unless($principal instanceof User || $principal instanceof AgentPrincipal, 401);

        return $principal;
    }
}
