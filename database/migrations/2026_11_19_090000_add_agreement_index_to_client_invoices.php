<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An agreement's invoices, in id order (#661).
 *
 * Issue, void and generation read an agreement's other invoices with row
 * locks. With no index naming the agreement, MariaDB scans the workspace's
 * `(workspace_id, id)` index instead and, under REPEATABLE READ, keeps a lock
 * on every entry it examines - another agreement's invoice, or an ad-hoc draft
 * with none, included. A hand edit of that ad-hoc draft, holding its row and
 * inserting the lines whose tenant key checks that same entry, then deadlocked
 * with the issue (IssueTimeLockOrderTest). Scanned through this index, the
 * read examines only the agreement's own invoices, whose writers already
 * serialise on the agreement lock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_invoices', function (Blueprint $table): void {
            $table->index(['workspace_id', 'client_agreement_id', 'id'], 'client_invoices_ws_agreement_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('client_invoices', function (Blueprint $table): void {
            $table->dropIndex('client_invoices_ws_agreement_id_index');
        });
    }
};
