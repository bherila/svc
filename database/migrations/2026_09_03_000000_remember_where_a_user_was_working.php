<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remember which workspace and client a person was last inside.
 *
 * The session already remembered the client, per workspace, and that is the
 * right place for it *within* a session. It is the wrong place for it across
 * one: signing in on a new device, or after the session cookie expires, put
 * everyone back on the workspace selector with no memory of the tenant and
 * client they had been working in every day for a year.
 *
 * Two columns on `users` rather than a visits table, because the question is
 * singular - where was this person last - and a table keyed by user and
 * workspace would be a history nothing reads. The session keeps its
 * per-workspace fidelity for the length of a session; this is the durable
 * fallback underneath it.
 *
 * ## Neither column is trusted on the way out
 *
 * A remembered id outlives the grant that produced it: a workspace membership
 * revoked, a project scope narrowed, a portal membership removed. Both reads
 * revalidate against the viewer's *current* options before routing anywhere, so
 * a stale value sends someone to the selector rather than into a tenant they
 * can no longer reach. `nullOnDelete` covers only the coarsest case - the
 * workspace or company being deleted outright - and is not the check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('last_workspace_id')
                ->nullable()
                ->after('remember_token')
                ->constrained('workspaces')
                ->nullOnDelete();
            // Not scoped to the workspace by a composite key: the pair is
            // checked on read against the options this viewer actually has,
            // which is a narrower test than a foreign key can express.
            $table->foreignId('last_client_company_id')
                ->nullable()
                ->after('last_workspace_id')
                ->constrained('client_companies')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('last_client_company_id');
            $table->dropConstrainedForeignId('last_workspace_id');
        });
    }
};
