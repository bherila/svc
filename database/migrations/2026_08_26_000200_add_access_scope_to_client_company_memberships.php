<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a portal user be narrowed to specific projects.
 *
 * Portal access is currently company-wide: anyone with a company membership
 * sees every client-visible project the company owns.
 *
 * `client_project_memberships` cannot express this. It carries a composite
 * foreign key into `workspace_memberships`, so a row there requires the user to
 * be a member of the workspace — that coupling is what stops orphaned project
 * rows from granting access, and it means the table describes internal staff.
 * External portal users are not workspace members and can never appear in it.
 *
 * So client-side scoping gets its own association, hanging off the company
 * membership that already grants portal entry. The scope is explicit and
 * defaults to the behaviour already in place: no project memberships exist
 * yet, so making narrowing implicit would blank every portal at deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_company_memberships', function (Blueprint $table): void {
            $table->string('access_scope', 20)->default('company')->after('role');
        });

        Schema::create('client_portal_project_access', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            // Named explicitly: the generated name is 65 characters and MySQL caps
            // identifiers at 64.
            $table->foreignId('client_company_membership_id');
            $table->foreign('client_company_membership_id', 'portal_access_membership_fk')
                ->references('id')->on('client_company_memberships')->cascadeOnDelete();
            $table->foreignId('client_project_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(
                ['client_company_membership_id', 'client_project_id'],
                'portal_project_access_unique',
            );
            $table->index(['workspace_id', 'client_project_id'], 'portal_project_access_workspace_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_portal_project_access');

        Schema::table('client_company_memberships', function (Blueprint $table): void {
            $table->dropColumn('access_scope');
        });
    }
};
