<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reviewer grants (docs/ux-overhaul.md section 7d, slice 10): a provider
 * workspace lets someone outside it review - approve or dispute - the hours and
 * expenses it bills one client for. Schema only; nothing here writes data.
 *
 * The table belongs to the workspace that gives the grant and is read from the
 * side of the person who receives it, so, like the shared-project tables, it
 * carries `granter_workspace_id` rather than a bare `workspace_id`. It is
 * reached only through ReviewerGrants, the one place that decides whether a
 * grant reaches a person.
 *
 * - The grant names one of the granter's clients, and optionally one of that
 *   client's projects. `(granter_workspace_id, client_company_id)` and
 *   `(granter_workspace_id, client_project_id)` are composite keys onto
 *   `client_companies(workspace_id, id)` and `client_projects(workspace_id, id)`,
 *   so a grant can never name another tenant's client or project.
 * - It names a grantee as an address (`reviewer_email`, normalised) or a workspace
 *   (`reviewer_workspace_id`), never both. `grantee_key` and `project_key` are
 *   the same two facts in a form a unique key can hold, because NULL is never
 *   equal to NULL.
 * - It is active while `active_marker` is 1. Revoking stamps `revoked_at` and
 *   clears the marker, which lets the unique key allow one active grant per
 *   grantee and scope without a partial index MariaDB does not have.
 *
 * Index and constraint names are explicit and under MariaDB's 64 characters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviewer_grants', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique('revgr_public_id_unique');
            $table->unsignedBigInteger('granter_workspace_id');
            $table->foreign('granter_workspace_id', 'revgr_granter_ws_fk')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->unsignedBigInteger('client_company_id');
            $table->unsignedBigInteger('client_project_id')->nullable();
            $table->unsignedBigInteger('project_key')->default(0);
            $table->string('grantee_kind', 16);
            $table->string('grantee_key', 64);
            $table->string('reviewer_email', 255)->nullable();
            $table->unsignedBigInteger('reviewer_workspace_id')->nullable();
            $table->foreign('reviewer_workspace_id', 'revgr_reviewer_ws_fk')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->string('reviewer_label', 255)->nullable();
            $table->unsignedBigInteger('granted_by_user_id')->nullable();
            $table->foreign('granted_by_user_id', 'revgr_granter_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by_user_id')->nullable();
            $table->foreign('revoked_by_user_id', 'revgr_revoker_fk')->references('id')->on('users')->nullOnDelete();
            $table->unsignedTinyInteger('active_marker')->nullable();
            $table->timestamps();
            $table->foreign(['granter_workspace_id', 'client_company_id'], 'revgr_granter_company_fk')
                ->references(['workspace_id', 'id'])->on('client_companies')->cascadeOnDelete();
            $table->foreign(['granter_workspace_id', 'client_project_id'], 'revgr_granter_project_fk')
                ->references(['workspace_id', 'id'])->on('client_projects')->cascadeOnDelete();
            $table->unique(['granter_workspace_id', 'client_company_id', 'project_key', 'grantee_key', 'active_marker'], 'revgr_one_active_unique');
            $table->index(['granter_workspace_id', 'active_marker'], 'revgr_granter_active_idx');
            $table->index(['reviewer_email', 'active_marker'], 'revgr_email_active_idx');
            $table->index(['reviewer_workspace_id', 'active_marker'], 'revgr_reviewer_ws_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviewer_grants');
    }
};
