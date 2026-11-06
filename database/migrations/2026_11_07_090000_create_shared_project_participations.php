<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shared projects (docs/ux-overhaul.md section 7a): a host workspace's project
 * that other workspaces take part in. Schema only; nothing here writes data.
 *
 * The two new tables belong to two workspaces at once, so neither carries a
 * bare `workspace_id`. That name says "this row has one owner", and every
 * tenant-owned query, route binding and audit in the application reads it that
 * way. They name `host_workspace_id` and `participant_workspace_id` instead,
 * which keeps them out of those paths: a participation can only be reached
 * through SharedProjectAccess, the one place that decides who may see a shared
 * project.
 *
 * - An invitation is a promise to an address. It grants nothing; access begins
 *   only when a participation row exists, and that row is written only by
 *   acceptance. A pending, declined, revoked or expired invitation therefore
 *   has no participation to read.
 * - A participation is active while `active_marker` is 1. Ending it (the host
 *   revokes, the participant leaves) stamps the matching timestamp and clears
 *   the marker, which is what lets the unique key allow one active participation
 *   per project and workspace without a partial index MariaDB does not have.
 * - `(host_workspace_id, client_project_id)` is a composite key onto
 *   client_projects(workspace_id, id): the project must live in the host.
 *
 * Three nullable columns elsewhere carry what a participation adds to existing
 * rows. Each defaults to "nothing is shared":
 *
 * - client_projects.shared_participation_id links a participant's own project
 *   to the participation it works against. Time, rates and invoices stay on the
 *   local project, in the local workspace.
 * - client_comments.shared_with_participants marks a comment as part of the
 *   shared conversation (default false: a host's existing comments stay internal),
 *   and author_workspace_id names the participant workspace that wrote one.
 * - client_task_assignments.assignee_workspace_id names the participant
 *   workspace of an assignee who is not a member of the host.
 *
 * Index and constraint names are explicit and under MariaDB's 64 characters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_project_participation_invitations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique('cppinv_public_id_unique');
            $table->foreignId('host_workspace_id')->constrained('workspaces', indexName: 'cppinv_host_workspace_fk')->cascadeOnDelete();
            $table->unsignedBigInteger('client_project_id');
            $table->string('email', 255);
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users', indexName: 'cppinv_inviter_fk')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users', indexName: 'cppinv_resolver_fk')->nullOnDelete();
            $table->timestamps();
            $table->foreign(['host_workspace_id', 'client_project_id'], 'cppinv_host_project_fk')
                ->references(['workspace_id', 'id'])->on('client_projects')->cascadeOnDelete();
            $table->index('email', 'cppinv_email_idx');
            $table->index(['host_workspace_id', 'client_project_id'], 'cppinv_host_project_idx');
        });

        Schema::create('client_project_participations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique('cppart_public_id_unique');
            $table->foreignId('host_workspace_id')->constrained('workspaces', indexName: 'cppart_host_workspace_fk')->cascadeOnDelete();
            $table->unsignedBigInteger('client_project_id');
            $table->foreignId('participant_workspace_id')->constrained('workspaces', indexName: 'cppart_participant_fk')->cascadeOnDelete();
            $table->foreignId('invitation_id')->nullable()->constrained('client_project_participation_invitations', indexName: 'cppart_invitation_fk')->nullOnDelete();
            $table->foreignId('granted_by_user_id')->nullable()->constrained('users', indexName: 'cppart_granter_fk')->nullOnDelete();
            $table->timestamp('granted_at');
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users', indexName: 'cppart_accepter_fk')->nullOnDelete();
            $table->timestamp('accepted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users', indexName: 'cppart_revoker_fk')->nullOnDelete();
            $table->timestamp('left_at')->nullable();
            $table->foreignId('left_by_user_id')->nullable()->constrained('users', indexName: 'cppart_leaver_fk')->nullOnDelete();
            $table->unsignedTinyInteger('active_marker')->nullable();
            $table->timestamps();
            $table->foreign(['host_workspace_id', 'client_project_id'], 'cppart_host_project_fk')
                ->references(['workspace_id', 'id'])->on('client_projects')->cascadeOnDelete();
            $table->unique(['client_project_id', 'participant_workspace_id', 'active_marker'], 'cppart_one_active_unique');
            $table->index(['participant_workspace_id', 'active_marker'], 'cppart_participant_active_idx');
            $table->index(['host_workspace_id', 'client_project_id'], 'cppart_host_project_idx');
        });

        Schema::table('client_projects', function (Blueprint $table): void {
            $table->foreignId('shared_participation_id')->nullable()
                ->constrained('client_project_participations', indexName: 'cp_shared_participation_fk')->nullOnDelete();
            $table->unique('shared_participation_id', 'cp_shared_participation_unique');
        });

        Schema::table('client_comments', function (Blueprint $table): void {
            $table->boolean('shared_with_participants')->default(false);
            $table->foreignId('author_workspace_id')->nullable()->constrained('workspaces', indexName: 'cc_author_workspace_fk')->nullOnDelete();
        });

        Schema::table('client_task_assignments', function (Blueprint $table): void {
            $table->foreignId('assignee_workspace_id')->nullable()->constrained('workspaces', indexName: 'cta_assignee_workspace_fk')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // SQLite cannot drop a foreign key by name, only by its columns.
        $mariadb = DB::connection()->getDriverName() !== 'sqlite';
        $drop = static fn (Blueprint $table, string $name, string $column) => $table->dropForeign($mariadb ? $name : [$column]);

        Schema::table('client_task_assignments', function (Blueprint $table) use ($drop): void {
            $drop($table, 'cta_assignee_workspace_fk', 'assignee_workspace_id');
            $table->dropColumn('assignee_workspace_id');
        });
        Schema::table('client_comments', function (Blueprint $table) use ($drop): void {
            $drop($table, 'cc_author_workspace_fk', 'author_workspace_id');
            $table->dropColumn(['shared_with_participants', 'author_workspace_id']);
        });
        Schema::table('client_projects', function (Blueprint $table) use ($drop): void {
            $drop($table, 'cp_shared_participation_fk', 'shared_participation_id');
            $table->dropUnique('cp_shared_participation_unique');
            $table->dropColumn('shared_participation_id');
        });
        Schema::dropIfExists('client_project_participations');
        Schema::dropIfExists('client_project_participation_invitations');
    }
};
