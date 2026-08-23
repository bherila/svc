<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Billing\InvoiceEmailService;
use App\Services\Billing\InvoiceFromTimeService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Billing\InvoiceNumberAllocator;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\Presenters\AgentInvoicePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AgentInvoiceMutationController extends Controller
{
    public function createDraft(Request $request, Workspace $workspace, AgentAccess $access, InvoiceFromTimeService $fromTime, InvoiceNumberAllocator $numbers, AgentInvoicePresenter $presenter): JsonResponse
    {
        $data = $request->validate(['company_id' => ['required', 'uuid'], 'currency' => ['nullable', 'string', 'size:3'], 'due_date' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:10000'], 'time_entry_ids' => ['array', 'max:100'], 'time_entry_ids.*' => ['uuid', 'distinct'], 'manual_lines' => ['array', 'max:100'], 'manual_lines.*.project_id' => ['nullable', 'uuid'], 'manual_lines.*.type' => ['required', 'string', 'max:40'], 'manual_lines.*.description' => ['required', 'string', 'max:10000'], 'manual_lines.*.quantity' => ['required'], 'manual_lines.*.unit_amount' => ['required', 'integer', 'min:0'], 'manual_lines.*.tax_amount' => ['nullable', 'integer', 'min:0']]);
        $user = $this->user($request);
        abort_unless($access->isWorkspaceManager($user, $workspace), 403);
        abort_unless(($data['time_entry_ids'] ?? []) !== [] || ($data['manual_lines'] ?? []) !== [], 422, 'Select time or provide a manual line.');
        $company = ClientCompany::query()->where('workspace_id', $workspace->id)->where('public_id', $data['company_id'])->firstOrFail();
        /** @var list<array<string, mixed>> $lines */
        $lines = [];
        foreach (($data['manual_lines'] ?? []) as $line) {
            if (! is_array($line)) {
                abort(422);
            }
            if (is_string($line['project_id'] ?? null)) {
                $line['client_project_id'] = ClientProject::query()->where('workspace_id', $workspace->id)->where('public_id', $line['project_id'])->value('id');
                abort_unless($line['client_project_id'] !== null, 422);
            }
            unset($line['project_id']);
            $line['sort_order'] = 0;

            $lines[] = $line;
        }
        $invoice = $fromTime->create($workspace, $company, ['invoice_number' => $numbers->next($workspace), 'currency' => strtoupper($data['currency'] ?? $workspace->default_currency), 'due_date' => $data['due_date'] ?? null, 'notes' => $data['notes'] ?? null], $data['time_entry_ids'] ?? [], $lines);

        return response()->json(['data' => $presenter->mutation($workspace, $invoice)], 201);
    }

    public function issue(Request $request, Workspace $workspace, string $invoice, AgentAccess $access, InvoiceLifecycleService $lifecycle, AgentInvoicePresenter $presenter): JsonResponse
    {
        $record = $this->invoice($workspace, $invoice, $access, $request);
        $data = $request->validate(['expected_version' => ['required', 'string', 'size:64'], 'confirm' => ['accepted']]);
        abort_unless(AgentApiVersion::matches($record, $data['expected_version']), 409);
        $record = $lifecycle->issue($record, $workspace);

        return response()->json(['data' => $presenter->mutation($workspace, $record)]);
    }

    public function void(Request $request, Workspace $workspace, string $invoice, AgentAccess $access, InvoiceLifecycleService $lifecycle, AgentInvoicePresenter $presenter): JsonResponse
    {
        $record = $this->invoice($workspace, $invoice, $access, $request);
        $data = $request->validate(['expected_version' => ['required', 'string', 'size:64'], 'reason' => ['required', 'string', 'max:1000'], 'confirm' => ['accepted']]);
        abort_unless(AgentApiVersion::matches($record, $data['expected_version']), 409);
        $record = $lifecycle->void($record, $workspace);

        return response()->json(['data' => $presenter->mutation($workspace, $record)]);
    }

    public function send(Request $request, Workspace $workspace, string $invoice, AgentAccess $access, InvoiceEmailService $email, AgentInvoicePresenter $presenter): JsonResponse
    {
        $record = $this->invoice($workspace, $invoice, $access, $request);
        $data = $request->validate(['expected_version' => ['required', 'string', 'size:64'], 'recipients' => ['required', 'array', 'min:1', 'max:10'], 'recipients.*' => ['email'], 'confirm' => ['accepted']]);
        abort_unless(AgentApiVersion::matches($record, $data['expected_version']), 409);
        $email->queue($record, $data['recipients'], $workspace);

        return response()->json(['data' => $presenter->mutation($workspace, $record)]);
    }

    private function invoice(Workspace $workspace, string $id, AgentAccess $access, Request $request): ClientInvoice
    {
        $user = $this->user($request);
        abort_unless($access->isWorkspaceManager($user, $workspace), 403);

        return ClientInvoice::query()->where('workspace_id', $workspace->id)->where('public_id', $id)->with('clientCompany')->firstOrFail();
    }

    private function user(Request $request): User
    {
        $p = $request->user();
        abort_unless($p instanceof AgentPrincipal, 401);

        return User::query()->findOrFail($p->id);
    }
}
