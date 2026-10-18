<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentInvoiceOperations;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Services\AgentApi\AgentMutationExecutor;
use App\Services\AgentApi\IssueInvoiceAction;
use App\Services\Authorization\AgentAccess;
use App\Services\Authorization\AgentTokenScopes;
use App\Services\Billing\InvoiceCorrectionReadService;
use App\Services\Billing\InvoiceCorrectionService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\ExplicitConfirmation;
use App\Support\AgentApi\Presenters\AgentInvoicePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

final class AgentInvoiceMutationController extends Controller
{
    public function createDraft(Request $request, Workspace $workspace, AgentInvoiceOperations $operations, AgentInvoicePresenter $presenter, AgentMutationContextFactory $contexts): JsonResponse
    {
        $context = $contexts->from($request);
        $invoice = $operations->createDraft($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey, $request->all());

        return response()->json(['data' => $presenter->mutation($workspace, $invoice)], 201);
    }

    public function updateDraft(Request $request, Workspace $workspace, string $invoice, AgentInvoiceOperations $operations, AgentInvoicePresenter $presenter, AgentMutationContextFactory $contexts): JsonResponse
    {
        $context = $contexts->from($request);
        $record = $operations->updateDraft($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey, $invoice, $request->all());

        return response()->json(['data' => $presenter->mutation($workspace, $record)]);
    }

    public function updateDetails(Request $request, Workspace $workspace, string $invoice, AgentAccess $access, InvoiceLifecycleService $lifecycle, AgentInvoicePresenter $presenter, AgentMutationContextFactory $contexts, AgentMutationExecutor $mutations): JsonResponse
    {
        $context = $contexts->from($request);
        $ids = $mutations->run($context->user, $workspace, $context->oauthClientId, 'invoices.update_details', $context->idempotencyKey, ['invoice_id' => $invoice, 'body' => $request->all()], function () use ($request, $workspace, $invoice, $access, $context, $lifecycle): array {
            $record = $this->authorizedInvoice($workspace, $invoice, $access, $context->user);
            $data = $request->validate([
                'expected_version' => ['required', 'string', 'size:64'],
                'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
                'scheduled_issue_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
                'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
            ]);
            $attributes = Arr::only($data, ['due_date', 'scheduled_issue_date', 'notes']);
            if ($attributes === []) {
                throw ValidationException::withMessages(['due_date' => 'Supply at least one of due_date, scheduled_issue_date or notes.']);
            }
            $record = $lifecycle->updateDraftDetails($record, $workspace, $data['expected_version'], $attributes);

            return [$record->public_id];
        }, fn (array $ids) => abort_unless($access->isWorkspaceManager($context->user, $workspace), 403));
        $record = $this->findInvoice($workspace, $ids[0] ?? null);

        return response()->json(['data' => $presenter->mutation($workspace, $record)]);
    }

    public function correct(Request $request, Workspace $workspace, string $invoice, AgentAccess $access, InvoiceCorrectionService $corrections, AgentInvoicePresenter $presenter, AgentMutationContextFactory $contexts, AgentMutationExecutor $mutations): JsonResponse
    {
        $context = $contexts->from($request);
        $ids = $mutations->run($context->user, $workspace, $context->oauthClientId, 'invoices.correct', $context->idempotencyKey, ['invoice_id' => $invoice, 'body' => $request->all()], function () use ($request, $workspace, $invoice, $access, $context, $corrections): array {
            $record = $this->authorizedInvoice($workspace, $invoice, $access, $context->user);
            $data = $request->validate([
                'expected_version' => ['required', 'string', 'size:64'],
                'reason' => ['required', 'string', 'max:500'],
                'confirm' => ['required', new ExplicitConfirmation],
                'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
                'lines' => ['sometimes', 'array', 'min:1', 'max:100'],
                'lines.*' => ['array:id,description,quantity,unit_amount,tax_amount'],
                'lines.*.id' => ['required', 'uuid', 'distinct'],
                'lines.*.description' => ['required', 'string', 'max:10000'],
                'lines.*.quantity' => ['required', 'regex:/^\d+(?:\.\d{1,4})?$/'],
                'lines.*.unit_amount' => ['required', 'integer'],
                'lines.*.tax_amount' => ['required', 'integer', 'min:0'],
            ]);
            $record = $corrections->correct(
                $record,
                $workspace,
                null,
                array_key_exists('due_date', $data),
                isset($data['due_date']) ? (string) $data['due_date'] : null,
                (string) $data['reason'],
                $data['lines'] ?? null,
                static function (ClientInvoice $locked) use ($data): void {
                    abort_unless(AgentApiVersion::matches($locked, $data['expected_version']), 409, 'The invoice has changed; read it and retry.');
                },
            );

            return [$record->public_id];
        }, fn (array $ids) => abort_unless($access->isWorkspaceManager($context->user, $workspace), 403));
        $record = $this->findInvoice($workspace, $ids[0] ?? null);

        return response()->json(['data' => $presenter->mutation($workspace, $record) + ['correction' => app(InvoiceCorrectionReadService::class)->currentAgentSummary($workspace, $record)]]);
    }

    public function correction(Request $request, Workspace $workspace, string $invoice, string $correction, AgentAccess $access, InvoiceCorrectionReadService $evidence): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User || $user instanceof AgentPrincipal, 401);
        abort_unless($access->isWorkspaceManager($user, $workspace), 403);

        return response()->json(['data' => $evidence->get($workspace, $this->findInvoice($workspace, $invoice), $correction)]);
    }

    public function discardDraft(Request $request, Workspace $workspace, string $invoice, AgentAccess $access, InvoiceLifecycleService $lifecycle, AgentInvoicePresenter $presenter, AgentMutationContextFactory $contexts, AgentMutationExecutor $mutations): JsonResponse
    {
        $context = $contexts->from($request);
        $ids = $mutations->run($context->user, $workspace, $context->oauthClientId, 'invoices.discard_draft', $context->idempotencyKey, ['invoice_id' => $invoice, 'body' => $request->all()], function () use ($request, $workspace, $invoice, $access, $context, $lifecycle): array {
            $record = $this->authorizedInvoice($workspace, $invoice, $access, $context->user);
            $data = $request->validate(['expected_version' => ['required', 'string', 'size:64'], 'reason' => ['required', 'string', 'max:1000'], 'confirm' => ['required', new ExplicitConfirmation]]);
            abort_unless(AgentApiVersion::matches($record, $data['expected_version']), 409);
            $record = $lifecycle->discardDraft($record, $workspace, $data['reason']);

            return [$record->public_id];
        }, fn (array $ids) => abort_unless($access->isWorkspaceManager($context->user, $workspace), 403));
        $record = $this->findInvoice($workspace, $ids[0] ?? null);

        return response()->json(['data' => $presenter->mutation($workspace, $record)]);
    }

    public function issue(Request $request, Workspace $workspace, string $invoice, IssueInvoiceAction $issue, AgentInvoicePresenter $presenter, AgentMutationContextFactory $contexts, AgentTokenScopes $scopes): JsonResponse
    {
        $context = $contexts->from($request);
        $ids = $issue->run(
            $context->user,
            $workspace,
            $context->oauthClientId,
            $context->idempotencyKey,
            $invoice,
            $request->all(),
            $scopes->allows($request, AgentApiScopes::PAYMENTS_RECORD),
        );
        $record = $this->findInvoice($workspace, $ids[0] ?? null);

        return response()->json(['data' => $presenter->mutation($workspace, $record)]);
    }

    public function void(Request $request, Workspace $workspace, string $invoice, AgentAccess $access, InvoiceLifecycleService $lifecycle, AgentInvoicePresenter $presenter, AgentMutationContextFactory $contexts, AgentMutationExecutor $mutations): JsonResponse
    {
        $context = $contexts->from($request);
        $ids = $mutations->run($context->user, $workspace, $context->oauthClientId, 'invoices.void', $context->idempotencyKey, ['invoice_id' => $invoice, 'body' => $request->all()], function () use ($request, $workspace, $invoice, $access, $context, $lifecycle): array {
            $record = $this->authorizedInvoice($workspace, $invoice, $access, $context->user);
            $data = $request->validate(['expected_version' => ['required', 'string', 'size:64'], 'reason' => ['required', 'string', 'max:1000'], 'confirm' => ['required', new ExplicitConfirmation]]);
            abort_unless(AgentApiVersion::matches($record, $data['expected_version']), 409);
            $record = $lifecycle->void($record, $workspace, $data['reason']);

            return [$record->public_id];
        }, fn (array $ids) => abort_unless($access->isWorkspaceManager($context->user, $workspace), 403));
        $record = $this->findInvoice($workspace, $ids[0] ?? null);

        return response()->json(['data' => $presenter->mutation($workspace, $record)]);
    }

    public function send(Request $request, Workspace $workspace, string $invoice, AgentInvoiceOperations $operations, AgentInvoicePresenter $presenter, AgentMutationContextFactory $contexts): JsonResponse
    {
        $context = $contexts->from($request);
        $record = $operations->send($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey, $invoice, $request->all());

        return response()->json(['data' => $presenter->mutation($workspace, $record)]);
    }

    private function authorizedInvoice(Workspace $workspace, string $id, AgentAccess $access, User $user): ClientInvoice
    {
        abort_unless($access->isWorkspaceManager($user, $workspace), 403);

        return $this->findInvoice($workspace, $id);
    }

    private function findInvoice(Workspace $workspace, ?string $id): ClientInvoice
    {
        return ClientInvoice::query()->where('workspace_id', $workspace->id)->where('public_id', $id)->with('clientCompany')->firstOrFail();
    }
}
