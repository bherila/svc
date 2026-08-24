<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Companion to the external-import table rename: renames the columns, and
 * the explicitly named indexes/uniques, that still spelled out the retired
 * "legacy migration" vocabulary. Foreign key constraint names created by
 * the historical migrations are left as-is — MySQL's native RENAME COLUMN
 * repoints them to the new column automatically without needing their
 * assigned name guessed, and guessing it wrong here would risk failing a
 * production deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_import_items', function (Blueprint $table): void {
            $table->renameColumn('legacy_migration_run_id', 'external_import_run_id');
        });
        Schema::table('external_import_items', function (Blueprint $table): void {
            $table->renameIndex('legacy_source_identity_unique', 'external_import_source_identity_unique');
            $table->renameIndex(
                'legacy_migration_items_target_type_target_public_id_index',
                'external_import_items_target_type_target_public_id_index',
            );
            $table->renameIndex(
                'legacy_migration_items_legacy_migration_run_id_status_index',
                'external_import_items_external_import_run_id_status_index',
            );
        });

        Schema::table('external_import_run_items', function (Blueprint $table): void {
            $table->renameColumn('legacy_migration_run_id', 'external_import_run_id');
            $table->renameColumn('legacy_migration_item_id', 'external_import_item_id');
        });
        Schema::table('external_import_run_items', function (Blueprint $table): void {
            $table->renameIndex('legacy_run_item_unique', 'external_import_run_item_unique');
            $table->renameIndex('lmri_run_observed_status_idx', 'eiri_run_observed_status_idx');
        });

        Schema::table('external_import_failures', function (Blueprint $table): void {
            $table->renameColumn('legacy_migration_run_id', 'external_import_run_id');
            $table->renameColumn('legacy_migration_item_id', 'external_import_item_id');
        });
        Schema::table('external_import_failures', function (Blueprint $table): void {
            $table->renameIndex('lmf_run_reason_idx', 'eif_run_reason_idx');
            $table->renameIndex('legacy_failure_identity_unique', 'external_import_failure_identity_unique');
        });

        Schema::table('external_import_attachment_copies', function (Blueprint $table): void {
            $table->renameColumn('legacy_migration_item_id', 'external_import_item_id');
        });
        Schema::table('external_import_attachment_copies', function (Blueprint $table): void {
            $table->renameIndex(
                'legacy_attachment_copies_workspace_id_copied_at_index',
                'eiac_workspace_copied_idx',
            );
        });

        Schema::table('external_import_runs', function (Blueprint $table): void {
            $table->renameIndex('lmr_source_identity_idx', 'eir_source_identity_idx');
            $table->renameIndex(
                'legacy_migration_runs_workspace_id_created_at_index',
                'external_import_runs_workspace_id_created_at_index',
            );
        });

        Schema::table('client_company_activity', function (Blueprint $table): void {
            $table->renameColumn('legacy_subject_id', 'external_subject_id');
        });

        Schema::table('client_invoice_email_deliveries', function (Blueprint $table): void {
            $table->renameColumn('legacy_metadata', 'external_metadata');
        });
    }

    public function down(): void
    {
        Schema::table('client_invoice_email_deliveries', function (Blueprint $table): void {
            $table->renameColumn('external_metadata', 'legacy_metadata');
        });

        Schema::table('client_company_activity', function (Blueprint $table): void {
            $table->renameColumn('external_subject_id', 'legacy_subject_id');
        });

        Schema::table('external_import_runs', function (Blueprint $table): void {
            $table->renameIndex('external_import_runs_workspace_id_created_at_index', 'legacy_migration_runs_workspace_id_created_at_index');
            $table->renameIndex('eir_source_identity_idx', 'lmr_source_identity_idx');
        });

        Schema::table('external_import_attachment_copies', function (Blueprint $table): void {
            $table->renameIndex('eiac_workspace_copied_idx', 'legacy_attachment_copies_workspace_id_copied_at_index');
        });
        Schema::table('external_import_attachment_copies', function (Blueprint $table): void {
            $table->renameColumn('external_import_item_id', 'legacy_migration_item_id');
        });

        Schema::table('external_import_failures', function (Blueprint $table): void {
            $table->renameIndex('external_import_failure_identity_unique', 'legacy_failure_identity_unique');
            $table->renameIndex('eif_run_reason_idx', 'lmf_run_reason_idx');
        });
        Schema::table('external_import_failures', function (Blueprint $table): void {
            $table->renameColumn('external_import_item_id', 'legacy_migration_item_id');
            $table->renameColumn('external_import_run_id', 'legacy_migration_run_id');
        });

        Schema::table('external_import_run_items', function (Blueprint $table): void {
            $table->renameIndex('eiri_run_observed_status_idx', 'lmri_run_observed_status_idx');
            $table->renameIndex('external_import_run_item_unique', 'legacy_run_item_unique');
        });
        Schema::table('external_import_run_items', function (Blueprint $table): void {
            $table->renameColumn('external_import_item_id', 'legacy_migration_item_id');
            $table->renameColumn('external_import_run_id', 'legacy_migration_run_id');
        });

        Schema::table('external_import_items', function (Blueprint $table): void {
            $table->renameIndex(
                'external_import_items_external_import_run_id_status_index',
                'legacy_migration_items_legacy_migration_run_id_status_index',
            );
            $table->renameIndex(
                'external_import_items_target_type_target_public_id_index',
                'legacy_migration_items_target_type_target_public_id_index',
            );
            $table->renameIndex('external_import_source_identity_unique', 'legacy_source_identity_unique');
        });
        Schema::table('external_import_items', function (Blueprint $table): void {
            $table->renameColumn('external_import_run_id', 'legacy_migration_run_id');
        });
    }
};
