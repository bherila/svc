<?php

namespace App\Services\LegacyMigration;

final class ImporterRegistry
{
    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return [
            $this->spec('users', 'users', 'id', 'user', 'bind_user'),
            $this->spec('companies', 'client_companies', 'id', 'company', 'write'),
            $this->spec('memberships', 'client_company_user', 'id', 'company_membership', 'write', [
                ['source_table' => 'client_companies', 'source_column' => 'client_company_id', 'required' => true],
                ['source_table' => 'users', 'source_column' => 'user_id', 'required' => true],
            ], 'client_company_memberships'),
            $this->spec('projects', 'client_projects', 'id', 'project', 'write', [
                ['source_table' => 'client_companies', 'source_column' => 'client_company_id', 'required' => true],
            ]),
            $this->spec('tasks', 'client_tasks', 'id', 'task', 'write', [
                ['source_table' => 'client_projects', 'source_column' => 'project_id', 'required' => true],
            ]),
            $this->spec('time', 'client_time_entries', 'id', 'time_entry', 'write', [
                ['source_table' => 'client_companies', 'source_column' => 'client_company_id', 'required' => true],
                ['source_table' => 'client_projects', 'source_column' => 'project_id', 'required' => true],
                ['source_table' => 'client_tasks', 'source_column' => 'task_id', 'required' => false],
                ['source_table' => 'users', 'source_column' => 'user_id', 'required' => true],
            ]),
            $this->spec('proposals', 'client_proposals', 'id', 'proposal', 'write', [
                ['source_table' => 'client_companies', 'source_column' => 'client_company_id', 'required' => true],
                ['source_table' => 'client_projects', 'source_column' => 'project_id', 'required' => false],
            ]),
            $this->spec('proposal_items', 'client_proposal_items', 'id', 'proposal_item', 'write', [
                ['source_table' => 'client_proposals', 'source_column' => 'client_proposal_id', 'required' => true],
            ]),
            $this->spec('agreements', 'client_agreements', 'id', 'agreement', 'write', [
                ['source_table' => 'client_companies', 'source_column' => 'client_company_id', 'required' => true],
                ['source_table' => 'client_proposals', 'source_column' => 'source_proposal_id', 'required' => false],
            ]),
            $this->spec('recurring_items', 'client_agreement_recurring_items', 'id', 'agreement_recurring_item', 'write', [
                ['source_table' => 'client_agreements', 'source_column' => 'client_agreement_id', 'required' => true],
            ]),
            $this->spec('invoices', 'client_invoices', 'client_invoice_id', 'invoice', 'write', [
                ['source_table' => 'client_companies', 'source_column' => 'client_company_id', 'required' => true],
                ['source_table' => 'client_agreements', 'source_column' => 'client_agreement_id', 'required' => false],
            ]),
            $this->spec('invoice_lines', 'client_invoice_lines', 'client_invoice_line_id', 'invoice_line', 'write', [
                ['source_table' => 'client_invoices', 'source_column' => 'client_invoice_id', 'required' => true],
                ['source_table' => 'client_agreements', 'source_column' => 'client_agreement_id', 'required' => false],
                ['source_table' => 'client_agreement_recurring_items', 'source_column' => 'client_agreement_recurring_item_id', 'required' => false],
            ]),
            $this->spec('payments', 'client_invoice_payments', 'client_invoice_payment_id', 'invoice_payment', 'write', [
                ['source_table' => 'client_invoices', 'source_column' => 'client_invoice_id', 'required' => true],
            ]),
            $this->spec('stripe_customers', 'client_company_stripe_customers', 'id', 'stripe_customer', 'write', [
                ['source_table' => 'client_companies', 'source_column' => 'client_company_id', 'required' => true],
            ], 'client_stripe_customers'),
            $this->spec('stripe_payment_methods', 'client_company_payment_methods', 'id', 'stripe_payment_method', 'write', [
                ['source_table' => 'client_companies', 'source_column' => 'client_company_id', 'required' => true],
            ], 'client_stripe_payment_methods'),
            $this->spec('stripe_events', 'client_invoice_stripe_events', 'id', 'stripe_event', 'write', [], 'client_stripe_events'),
            $this->spec('stripe_payment_references', 'client_invoice_stripe_payments', 'id', 'stripe_payment_reference', 'planned_reference'),
            $this->spec('attachments_companies', 'files_for_client_companies', 'id', 'attachment', 'planned_copy'),
            $this->spec('attachments_projects', 'files_for_projects', 'id', 'attachment', 'planned_copy'),
            $this->spec('attachments_tasks', 'files_for_tasks', 'id', 'attachment', 'planned_copy'),
            $this->spec('attachments_agreements', 'files_for_agreements', 'id', 'attachment', 'planned_copy'),
        ];
    }

    /** @param list<array{source_table: string, source_column: string, required: bool}> $parents */
    /**
     * @param  list<array{source_table: string, source_column: string, required: bool}>  $parents
     * @return array<string, mixed>
     */
    private function spec(string $name, string $sourceTable, string $sourceKey, string $targetType, string $action, array $parents = [], ?string $targetTable = null): array
    {
        return [
            'name' => $name,
            'source_table' => $sourceTable,
            'source_key' => $sourceKey,
            'target_type' => $targetType,
            'target_table' => $targetTable ?? $sourceTable,
            'action' => $action,
            'parents' => $parents,
            'date_columns' => ['created_at', 'updated_at', 'date_worked', 'issue_date', 'payment_date', 'start_date', 'active_date'],
        ];
    }
}
