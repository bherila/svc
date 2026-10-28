<?php

namespace App\Support\AgentApi\Presenters;

use App\Models\ClientExpense;
use App\Support\AgentApi\AgentApiVersion;
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
            'version' => AgentApiVersion::for($expense),
            'can_edit' => $editable,
            'can_delete' => $editable,
        ];
    }
}
