<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Renames the onboarding-import ledger tables so their names describe the
 * generic "import data from an external source" feature rather than a
 * specific predecessor system. The historical create-table migrations are
 * left untouched; this migration only renames tables that already carry
 * production rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('legacy_migration_runs', 'external_import_runs');
        Schema::rename('legacy_migration_items', 'external_import_items');
        Schema::rename('legacy_migration_run_items', 'external_import_run_items');
        Schema::rename('legacy_migration_failures', 'external_import_failures');
        Schema::rename('legacy_attachment_copies', 'external_import_attachment_copies');
    }

    public function down(): void
    {
        Schema::rename('external_import_attachment_copies', 'legacy_attachment_copies');
        Schema::rename('external_import_failures', 'legacy_migration_failures');
        Schema::rename('external_import_run_items', 'legacy_migration_run_items');
        Schema::rename('external_import_items', 'legacy_migration_items');
        Schema::rename('external_import_runs', 'legacy_migration_runs');
    }
};
