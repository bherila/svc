<?php

namespace App\Support\AgentApi\Presenters;

use App\Models\ClientExpense;
use App\Models\ClientInvoice;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Approval\ClientApprovalVerdict;
use App\Support\Approval\ClientReview;
use App\Support\Approval\DisputeHold;
use App\Support\Approval\DisputeSettlement;
use App\Support\Billing\InvoiceStatus;
use App\Support\Expenses\ExpenseStatus;

/** Presents only already-loaded, workspace-scoped expense facts. */
final class AgentExpensePresenter
{
    /** Safe receipt header fields, without resolving company/project/finance data.
     * @return array{description:string,spent_on:string,amount:int,currency:string,status:string} */
    public static function summary(ClientExpense $expense): array
    {
        return ['description' => $expense->description, 'spent_on' => $expense->spent_on->toDateString(),
            'amount' => $expense->amount, 'currency' => $expense->currency, 'status' => $expense->status];
    }

    /**
     * The workspace-scoped invoice behind an expense, for {@see ClientReview()}.
     *
     * @return array<string, \Closure>
     */
    public static function invoiceRelations(int $workspaceId): array
    {
        return [
            'invoiceLine' => fn ($line) => $line->where('client_invoice_lines.workspace_id', $workspaceId),
            'invoiceLine.invoice' => fn ($invoice) => $invoice->where('client_invoices.workspace_id', $workspaceId),
        ];
    }

    /**
     * The client's decision on the expense and any dispute's settlement
     * (docs/ux-overhaul.md 7b″).
     *
     * @return array<string, mixed>
     */
    public static function clientReview(ClientExpense $expense): array
    {
        $expense->loadMissing(self::invoiceRelations((int) $expense->workspace_id));
        $invoice = $expense->invoiceLine?->invoice;
        $status = $invoice instanceof ClientInvoice && (int) $invoice->workspace_id === (int) $expense->workspace_id ? (string) $invoice->status : null;
        $settlement = DisputeSettlement::tryFrom((string) $expense->client_dispute_settlement);

        return ClientReview::present(
            ClientApprovalVerdict::judge(
                $expense->client_approval_source,
                $expense->client_approved_at !== null,
                $expense->client_disputed_at !== null,
                $status === InvoiceStatus::Paid->value,
                $settlement === DisputeSettlement::WrittenOff,
            ),
            DisputeHold::holds($expense->client_disputed_at !== null, in_array($status, InvoiceStatus::charged(), true)),
            $expense->client_dispute_note,
            $expense->client_disputed_at,
            $settlement,
            $expense->client_dispute_reply,
            $expense->client_dispute_settled_at,
        );
    }

    /** @return array<string, mixed> */
    public function present(ClientExpense $expense, bool $canWrite, bool $isWorkspaceManager = false): array
    {
        $summary = self::summary($expense);
        $editable = $canWrite && ExpenseStatus::isEditableValue($expense->status);

        return [
            'id' => $expense->public_id,
            'company_id' => $expense->clientCompany->public_id,
            'project_id' => $expense->project?->public_id,
            'spent_on' => $summary['spent_on'],
            'amount' => $summary['amount'],
            'currency' => $summary['currency'],
            'description' => $summary['description'],
            'external_finance_transaction_uuid' => $isWorkspaceManager ? $expense->external_finance_transaction_uuid : null,
            'status' => $summary['status'],
            'status_label' => match (ExpenseStatus::tryFrom($expense->status)) {
                ExpenseStatus::Draft => 'Draft',
                ExpenseStatus::Approved => 'Approved',
                ExpenseStatus::Invoiced => 'Invoiced',
                null => 'Unknown',
            },
            'client_review' => self::clientReview($expense),
            'version' => AgentApiVersion::for($expense),
            'can_edit' => $editable,
            'can_delete' => $editable,
        ];
    }
}
