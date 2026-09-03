<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Services\AgentApi\AgentMutationExecutor;
use App\Services\Authorization\AgentAccess;
use App\Services\Billing\InvoiceEmailService;
use App\Services\Billing\InvoiceFromTimeService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\Presenters\AgentInvoicePresenter;
use App\Support\Billing\InvoiceEmailDraft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

final class AgentInvoiceMutationController extends Controller
{
    public function createDraft(
        Request $request,
        Workspace $workspace,
        AgentAccess $access,
        InvoiceFromTimeService $fromTime,
        AgentInvoicePresenter $presenter,
        AgentMutationContextFactory $contexts,
        AgentMutationExecutor $mutations,
    ): JsonResponse {
        $context = $contexts->from($request);
        $ids = $mutations->run(
            $context->user,
            $workspace,
            $context->oauthClientId,
            'invoices.create_draft',
            $context->idempotencyKey,
            $request->all(),
            function () use ($request, $workspace, $access, $context, $fromTime): array {
                $data = $request->validate($this->draftRules());
                abort_unless($access->isWorkspaceManager($context->user, $workspace), 403);
                $company = ClientCompany::query()->where('workspace_id', $workspace->id)->where('public_id', $data['company_id'])->firstOrFail();
                $invoice = $fromTime->create(
                    $workspace,
                    $company,
                    Arr::only($data, ['currency', 'due_date', 'notes']),
                    $data['time_entry_ids'] ?? [],
                    $data['manual_lines'] ?? [],
                );

                return [$invoice->public_id];
            },
            fn (array $ids) => abort_unless($access->isWorkspaceManager($context->user, $workspace), 403),
        );
        $invoice = $this->findInvoice($workspace, $ids[0] ?? null);

        return response()->json(['data' => $presenter->mutation($workspace, $invoice)], 201);
    }

    public function updateDraft(Request $request, Workspace $workspace, string $invoice, AgentAccess $access, InvoiceFromTimeService $fromTime, AgentInvoicePresenter $presenter, AgentMutationContextFactory $contexts, AgentMutationExecutor $mutations): JsonResponse
    {
        $context = $contexts->from($request);
        $ids = $mutations->run($context->user, $workspace, $context->oauthClientId, 'invoices.update_draft', $context->idempotencyKey, ['invoice_id' => $invoice, 'body' => $request->all()], function () use ($request, $workspace, $invoice, $access, $context, $fromTime): array {
            $record = $this->authorizedInvoice($workspace, $invoice, $access, $context->user);
            $data = $request->validate($this->draftRules(true));
            $record = $fromTime->updateDraft(
                $record,
                $workspace,
                $data['expected_version'],
                Arr::only($data, ['currency', 'due_date', 'notes']),
                $data['time_entry_ids'],
                $data['manual_lines'],
            );

            return [$record->public_id];
        }, fn (array $ids) => abort_unless($access->isWorkspaceManager($context->user, $workspace), 403));
        $record = $this->findInvoice($workspace, $ids[0] ?? null);

        return response()->json(['data' => $presenter->mutation($workspace, $record)]);
    }

    public function discardDraft(Request $request, Workspace $workspace, string $invoice, AgentAccess $access, InvoiceLifecycleService $lifecycle, AgentInvoicePresenter $presenter, AgentMutationContextFactory $contexts, AgentMutationExecutor $mutations): JsonResponse
    {
        $context = $contexts->from($request);
        $ids = $mutations->run($context->user, $workspace, $context->oauthClientId, 'invoices.discard_draft', $context->idempotencyKey, ['invoice_id' => $invoice, 'body' => $request->all()], function () use ($request, $workspace, $invoice, $access, $context, $lifecycle): array {
            $record = $this->authorizedInvoice($workspace, $invoice, $access, $context->user);
            $data = $request->validate(['expected_version' => ['required', 'string', 'size:64'], 'reason' => ['required', 'string', 'max:1000'], 'confirm' => ['accepted']]);
            abort_unless(AgentApiVersion::matches($record, $data['expected_version']), 409);
            $record = $lifecycle->discardDraft($record, $workspace, $data['reason']);

            return [$record->public_id];
        }, fn (array $ids) => abort_unless($access->isWorkspaceManager($context->user, $workspace), 403));
        $record = $this->findInvoice($workspace, $ids[0] ?? null);

        return response()->json(['data' => $presenter->mutation($workspace, $record)]);
    }

    public function issue(Request $request, Workspace $workspace, string $invoice, AgentAccess $access, InvoiceLifecycleService $lifecycle, AgentInvoicePresenter $presenter, AgentMutationContextFactory $contexts, AgentMutationExecutor $mutations): JsonResponse
    {
        $context = $contexts->from($request);
        $ids = $mutations->run($context->user, $workspace, $context->oauthClientId, 'invoices.issue', $context->idempotencyKey, ['invoice_id' => $invoice, 'body' => $request->all()], function () use ($request, $workspace, $invoice, $access, $context, $lifecycle): array {
            $record = $this->authorizedInvoice($workspace, $invoice, $access, $context->user);
            $data = $request->validate(['expected_version' => ['required', 'string', 'size:64'], 'confirm' => ['accepted']]);
            abort_unless(AgentApiVersion::matches($record, $data['expected_version']), 409);
            $record = $lifecycle->issue($record, $workspace);

            return [$record->public_id];
        }, fn (array $ids) => abort_unless($access->isWorkspaceManager($context->user, $workspace), 403));
        $record = $this->findInvoice($workspace, $ids[0] ?? null);

        return response()->json(['data' => $presenter->mutation($workspace, $record)]);
    }

    public function void(Request $request, Workspace $workspace, string $invoice, AgentAccess $access, InvoiceLifecycleService $lifecycle, AgentInvoicePresenter $presenter, AgentMutationContextFactory $contexts, AgentMutationExecutor $mutations): JsonResponse
    {
        $context = $contexts->from($request);
        $ids = $mutations->run($context->user, $workspace, $context->oauthClientId, 'invoices.void', $context->idempotencyKey, ['invoice_id' => $invoice, 'body' => $request->all()], function () use ($request, $workspace, $invoice, $access, $context, $lifecycle): array {
            $record = $this->authorizedInvoice($workspace, $invoice, $access, $context->user);
            $data = $request->validate(['expected_version' => ['required', 'string', 'size:64'], 'reason' => ['required', 'string', 'max:1000'], 'confirm' => ['accepted']]);
            abort_unless(AgentApiVersion::matches($record, $data['expected_version']), 409);
            $record = $lifecycle->void($record, $workspace, $data['reason']);

            return [$record->public_id];
        }, fn (array $ids) => abort_unless($access->isWorkspaceManager($context->user, $workspace), 403));
        $record = $this->findInvoice($workspace, $ids[0] ?? null);

        return response()->json(['data' => $presenter->mutation($workspace, $record)]);
    }

    public function send(Request $request, Workspace $workspace, string $invoice, AgentAccess $access, InvoiceEmailService $email, AgentInvoicePresenter $presenter, AgentMutationContextFactory $contexts, AgentMutationExecutor $mutations): JsonResponse
    {
        $context = $contexts->from($request);
        $ids = $mutations->run($context->user, $workspace, $context->oauthClientId, 'invoices.send', $context->idempotencyKey, ['invoice_id' => $invoice, 'body' => $request->all()], function () use ($request, $workspace, $invoice, $access, $context, $email): array {
            $record = $this->authorizedInvoice($workspace, $invoice, $access, $context->user);
            $data = $request->validate(['expected_version' => ['required', 'string', 'size:64'], 'recipients' => ['required', 'array', 'min:1', 'max:10'], 'recipients.*' => ['email'], 'confirm' => ['accepted']]);
            abort_unless(AgentApiVersion::matches($record, $data['expected_version']), 409);
            // Registered here and sent when this mutation's transaction
            // commits: the receipt and the effect have to land together, and an
            // email already gone is not something a rollback can take back.
            $email->sendAfterCommit($record, InvoiceEmailDraft::of(
                $data['recipients'],
                [],
                $email->defaultSubject($record),
                null,
            ), $workspace);

            return [$record->public_id];
        }, fn (array $ids) => abort_unless($access->isWorkspaceManager($context->user, $workspace), 403));
        $record = $this->findInvoice($workspace, $ids[0] ?? null);

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

    /** @return array<string, list<mixed>> */
    private function draftRules(bool $updating = false): array
    {
        $rules = [
            'currency' => ['nullable', 'string', 'size:3'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'time_entry_ids' => [$updating ? 'present' : 'sometimes', 'array', 'max:100'],
            'time_entry_ids.*' => ['uuid', 'distinct'],
            'manual_lines' => [$updating ? 'present' : 'sometimes', 'array', 'max:100'],
            'manual_lines.*' => ['array:project_id,type,description,quantity,unit_amount,tax_amount'],
            'manual_lines.*.project_id' => ['nullable', 'uuid'],
            'manual_lines.*.type' => ['required', 'string', 'max:40'],
            'manual_lines.*.description' => ['required', 'string', 'max:10000'],
            'manual_lines.*.quantity' => ['required'],
            'manual_lines.*.unit_amount' => ['required', 'integer', 'min:0'],
            'manual_lines.*.tax_amount' => ['nullable', 'integer', 'min:0'],
        ];
        if ($updating) {
            $rules['expected_version'] = ['required', 'string', 'size:64'];
        } else {
            $rules['company_id'] = ['required', 'uuid'];
        }

        return $rules;
    }
}
