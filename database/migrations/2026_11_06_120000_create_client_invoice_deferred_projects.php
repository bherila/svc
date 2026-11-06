<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The projects an invoice's stored deferred-work snapshot discloses (#610).
 *
 * The snapshot itself is JSON on `client_invoices.deferred_work_statement`;
 * this is its relational index, written with it, so the portal's invoice grant
 * query can require every disclosed project to be granted in one bounded SQL
 * query rather than reading JSON or one row per entry.
 *
 * The project column carries no foreign key on purpose: like an invoice
 * line's attribution, it records which project the work belonged to when the
 * document was composed, and the invoice outlives that project. A row naming
 * a project that is gone matches no grant, so it keeps the invoice hidden from
 * project-scoped viewers instead of silently dropping its lineage.
 *
 * No backfill: legacy (version 1) snapshots recorded no project and cannot be
 * reconstructed from today's mutable time entries. The grant query treats a
 * legacy snapshot that lists any entry as unverifiable instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_invoice_deferred_projects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('client_invoice_id');
            $table->unsignedBigInteger('client_project_id');

            $table->unique(['workspace_id', 'client_invoice_id', 'client_project_id'], 'cidp_invoice_project_unique');
            $table->index(['workspace_id', 'client_project_id'], 'cidp_ws_project_idx');
            $table->foreign(['workspace_id', 'client_invoice_id'], 'cidp_ws_invoice_fk')
                ->references(['workspace_id', 'id'])->on('client_invoices')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_invoice_deferred_projects');
    }
};
