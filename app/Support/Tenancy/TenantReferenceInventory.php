<?php

namespace App\Support\Tenancy;

/**
 * Every tenant-owned column that names another tenant-owned row.
 *
 * The list is the single description of the invariant "a child row's parent
 * lives in the same workspace". Three things read it, and they disagree loudly
 * if it drifts:
 *
 * - `svc:schema:audit-tenant-fks` counts the rows that break each entry.
 * - `TenantForeignKeyInventoryTest` asserts each enforced entry is a real
 *   composite foreign key in the live schema, and that no tenant-owned column
 *   is missing from the list altogether.
 * - `docs/client-management/tenant-foreign-keys.md` explains the exemptions.
 *
 * Enforcement is a composite foreign key `(workspace_id, <column>)` on the child
 * referencing `(workspace_id, id)` on the parent, added by
 * `2026_08_31_000200_add_composite_tenant_foreign_keys`.
 */
final class TenantReferenceInventory
{
    /**
     * Why a nullable column carrying an existing `ON DELETE SET NULL` rule cannot
     * also carry a composite key.
     *
     * A composite key pairs the column with `workspace_id`, which is NOT NULL on
     * every child table here. InnoDB refuses `ON DELETE SET NULL` on a foreign key
     * containing a NOT NULL column (errno 1830), so the composite key can only be
     * RESTRICT or CASCADE - and either one contradicts the single-column rule that
     * already governs the same column, blocking or over-reaching a parent delete
     * the schema deliberately allows.
     */
    public const REASON_SET_NULL = 'nullable column whose single-column rule is ON DELETE SET NULL; a composite key over the NOT NULL workspace_id cannot express SET NULL (InnoDB errno 1830) and any other rule would contradict it';

    /**
     * Why a nullable attribution column carries no referential constraint at all.
     *
     * These name the agreement, schedule, or recurring item a charge came from.
     * The invoice is the financial record and outlives whatever explains it, so
     * the schema has always allowed the named row to disappear. Adding a composite
     * key would newly refuse that deletion, and the SET NULL that would preserve
     * today's behaviour is barred by errno 1830 exactly as above.
     */
    public const REASON_LINEAGE = 'nullable attribution column with no referential constraint by design: the invoice record outlives the row that explains it, and SET NULL is barred over the NOT NULL workspace_id (InnoDB errno 1830)';

    /**
     * Parent tables that need a unique index on `(workspace_id, id)`.
     *
     * `id` is already the primary key; the extra index exists only so InnoDB has
     * something that can serve the composite foreign keys below.
     *
     * @return list<string>
     */
    public static function parentTables(): array
    {
        $parents = [];

        foreach (self::all() as $reference) {
            if ($reference->enforced) {
                $parents[$reference->parentTable] = true;
            }
        }

        return array_keys($parents);
    }

    /** @return list<TenantReference> */
    public static function all(): array
    {
        return [
            // Companies own everything below them.
            TenantReference::enforcedBy('client_company_memberships', 'client_company_id', 'client_companies', 'ccm_ws_company_fk'),
            TenantReference::enforcedBy('client_company_activity', 'client_company_id', 'client_companies', 'cca_ws_company_fk'),
            TenantReference::enforcedBy('client_projects', 'client_company_id', 'client_companies', 'cp_ws_company_fk'),
            TenantReference::enforcedBy('client_proposals', 'client_company_id', 'client_companies', 'cpr_ws_company_fk'),
            TenantReference::enforcedBy('client_agreements', 'client_company_id', 'client_companies', 'cag_ws_company_fk'),
            TenantReference::enforcedBy('client_billing_schedules', 'client_company_id', 'client_companies', 'cbs_ws_company_fk'),
            TenantReference::enforcedBy('client_invoices', 'client_company_id', 'client_companies', 'ci_ws_company_fk'),
            TenantReference::enforcedBy('client_time_entries', 'client_company_id', 'client_companies', 'cte_ws_company_fk'),
            TenantReference::enforcedBy('client_stripe_customers', 'client_company_id', 'client_companies', 'csc_ws_company_fk'),
            TenantReference::enforcedBy('client_stripe_payment_methods', 'client_company_id', 'client_companies', 'cspm_ws_company_fk'),

            // Projects.
            TenantReference::enforcedBy('client_tasks', 'client_project_id', 'client_projects', 'ct_ws_project_fk'),
            TenantReference::enforcedBy('client_time_entries', 'client_project_id', 'client_projects', 'cte_ws_project_fk'),
            TenantReference::enforcedBy('client_project_memberships', 'client_project_id', 'client_projects', 'cpm_ws_project_fk'),
            TenantReference::enforcedBy('client_portal_project_access', 'client_project_id', 'client_projects', 'cppa_ws_project_fk'),

            // Portal memberships.
            TenantReference::enforcedBy('client_portal_project_access', 'client_company_membership_id', 'client_company_memberships', 'cppa_ws_membership_fk'),

            // Proposals and agreements.
            TenantReference::enforcedBy('client_proposal_items', 'client_proposal_id', 'client_proposals', 'cpi_ws_proposal_fk'),
            TenantReference::enforcedBy('client_agreement_recurring_items', 'client_agreement_id', 'client_agreements', 'cari_ws_agreement_fk'),
            TenantReference::enforcedBy('client_billing_schedules', 'client_agreement_id', 'client_agreements', 'cbs_ws_agreement_fk'),

            // Invoices and money.
            TenantReference::enforcedBy('client_invoice_lines', 'client_invoice_id', 'client_invoices', 'cil_ws_invoice_fk'),
            TenantReference::enforcedBy('client_invoice_payments', 'client_invoice_id', 'client_invoices', 'cip_ws_invoice_fk'),
            TenantReference::enforcedBy('client_invoice_email_deliveries', 'client_invoice_id', 'client_invoices', 'cied_ws_invoice_fk'),
            TenantReference::enforcedBy('payment_reconciliations', 'client_invoice_payment_id', 'client_invoice_payments', 'pr_ws_payment_fk'),
            TenantReference::enforcedBy('client_invoice_line_time_entries', 'client_invoice_line_id', 'client_invoice_lines', 'cilte_ws_line_fk'),
            TenantReference::enforcedBy('client_invoice_line_time_entries', 'client_time_entry_id', 'client_time_entries', 'cilte_ws_time_entry_fk'),

            // Stripe.
            TenantReference::enforcedBy('client_stripe_payment_methods', 'client_stripe_customer_id', 'client_stripe_customers', 'cspm_ws_customer_fk'),

            // Exemptions. Each one is still counted by the audit command.
            TenantReference::exempt('client_proposals', 'client_project_id', 'client_projects', self::REASON_SET_NULL),
            TenantReference::exempt('client_agreements', 'client_project_id', 'client_projects', self::REASON_SET_NULL),
            TenantReference::exempt('client_agreements', 'source_proposal_id', 'client_proposals', self::REASON_SET_NULL),
            TenantReference::exempt('client_invoice_lines', 'client_project_id', 'client_projects', self::REASON_SET_NULL),
            TenantReference::exempt('client_tasks', 'client_invoice_line_id', 'client_invoice_lines', self::REASON_SET_NULL),
            TenantReference::exempt('client_time_entries', 'client_task_id', 'client_tasks', self::REASON_SET_NULL),
            TenantReference::exempt('client_time_entries', 'split_from_time_entry_id', 'client_time_entries', self::REASON_SET_NULL),
            TenantReference::exempt('external_import_attachment_copies', 'client_attachment_id', 'client_attachments', self::REASON_SET_NULL),
            // Declared as lineage rather than plain exemptions: the row these
            // name may legitimately be deleted, so the audit asks whether an
            // existing parent is in the wrong workspace instead of treating a
            // permitted deletion as a violation it can never clear.
            TenantReference::lineage('client_invoices', 'client_agreement_id', 'client_agreements', self::REASON_LINEAGE),
            TenantReference::lineage('client_invoices', 'client_billing_schedule_id', 'client_billing_schedules', self::REASON_LINEAGE),
            TenantReference::lineage('client_invoice_lines', 'client_agreement_id', 'client_agreements', self::REASON_LINEAGE),
            TenantReference::lineage('client_invoice_lines', 'client_agreement_recurring_item_id', 'client_agreement_recurring_items', self::REASON_LINEAGE),

            // stripe_payment_method_states is a webhook-ordering cache whose own
            // workspace_id is nullable: a payment-method event can arrive before
            // anything in this system knows which tenant it belongs to. Every
            // tenant column on it is nullable and SET NULL, so both bars above
            // apply at once.
            TenantReference::exempt('stripe_payment_method_states', 'client_company_id', 'client_companies', self::REASON_SET_NULL),
            TenantReference::exempt('stripe_payment_method_states', 'client_stripe_customer_id', 'client_stripe_customers', self::REASON_SET_NULL),
        ];
    }
}
