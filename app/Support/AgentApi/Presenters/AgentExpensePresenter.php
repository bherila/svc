<?php

namespace App\Support\AgentApi\Presenters;

use App\Models\ClientExpense;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Expenses\ExpenseStatus;

/** Presents only already-loaded, workspace-scoped expense facts. */
final class AgentExpensePresenter
{
    /** @return array<string, mixed> */
    public function present(ClientExpense $expense, bool $canWrite): array
    {
        $editable = $canWrite && ExpenseStatus::isEditableValue($expense->status);

        return [
            'id' => $expense->public_id,
            'company_id' => $expense->clientCompany->public_id,
            'project_id' => $expense->project?->public_id,
            'spent_on' => $expense->spent_on->toDateString(),
            'amount' => $expense->amount,
            'currency' => $expense->currency,
            'description' => $expense->description,
            'status' => $expense->status,
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
