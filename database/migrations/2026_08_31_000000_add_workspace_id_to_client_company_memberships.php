<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `client_company_memberships` is tenant-owned and had no `workspace_id`.
 *
 * It was the one table in the tenant graph reachable only through its parent's
 * authority: a membership named a company and nothing else, so every question
 * about which workspace it belonged to was answered by a join that a caller
 * could forget. That is the exact shape #113 exists to make unrepresentable, and
 * the composite key cannot be added to a table with no workspace column.
 *
 * The backfill is total and derivable: `client_company_id` is NOT NULL and
 * carries a cascade foreign key, so every row has a company, and a company has
 * exactly one workspace. Nothing is invented and nothing is guessed. If any row
 * still lacks a workspace afterwards the migration aborts with a count rather
 * than deleting the row or inventing a tenant for it - a membership grants
 * portal access to client records, and the wrong answer here is worse than a
 * failed deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_company_memberships', function (Blueprint $table): void {
            $table->foreignId('workspace_id')->nullable()->after('id');
        });

        // A correlated subquery rather than an UPDATE ... JOIN: the join syntax
        // differs between MariaDB and SQLite, this form is identical on both.
        DB::statement(<<<'SQL'
            update client_company_memberships
            set workspace_id = (
                select client_companies.workspace_id
                from client_companies
                where client_companies.id = client_company_memberships.client_company_id
            )
        SQL);

        $unattributed = DB::table('client_company_memberships')->whereNull('workspace_id')->count();

        if ($unattributed > 0) {
            throw new RuntimeException(
                "Refusing to make client_company_memberships.workspace_id NOT NULL: {$unattributed} row(s) name a company that does not exist. Resolve them before migrating."
            );
        }

        Schema::table('client_company_memberships', function (Blueprint $table): void {
            $table->unsignedBigInteger('workspace_id')->nullable(false)->change();
        });

        // An explicit index before the key, so nothing implicit is created and
        // nothing borrowed is depended on.
        //
        // InnoDB needs an index whose leftmost column is `workspace_id` to serve
        // the key below. Without this one it would take the unique
        // `(workspace_id, id)` that 2026_08_31_000100 adds - and then rolling that
        // migration back is refused with errno 1553, because this key still needs
        // it. Migration order makes that unfixable from the other end: 000100
        // rolls back before this file does.
        //
        // SQLite needs no such index and reports no such error, which is why this
        // only ever appeared on the MariaDB lane.
        Schema::table('client_company_memberships', function (Blueprint $table): void {
            $table->index('workspace_id', 'ccm_workspace_idx');
        });

        // Laravel's generated name for this key is 47 characters, so it stays
        // inside MariaDB's 64-character identifier limit and `down()` can drop the
        // key by column - the only form SQLite's grammar accepts.
        Schema::table('client_company_memberships', function (Blueprint $table): void {
            $table->foreign('workspace_id')
                ->references('id')
                ->on('workspaces')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('client_company_memberships', function (Blueprint $table): void {
            $table->dropForeign(['workspace_id']);
        });

        // After the key, never before: InnoDB refuses to drop an index a foreign
        // key still depends on.
        Schema::table('client_company_memberships', function (Blueprint $table): void {
            $table->dropIndex('ccm_workspace_idx');
        });

        Schema::table('client_company_memberships', function (Blueprint $table): void {
            $table->dropColumn('workspace_id');
        });
    }
};
