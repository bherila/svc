<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The lock key for a workspace's manual client-email budget.
 *
 * `invoices.send` and a manual payment reminder each count the recipients the
 * workspace addressed by hand in the last day and then insert their own row;
 * two at once could each count under the limit and both insert. Each first
 * upserts this row and locks it, so they are serialized per workspace, without
 * locking the workspace row every workspace-scoped insert's foreign key check
 * reads (the agent API's receipt insert among them, which would make two
 * concurrent sends deadlock on the upgrade).
 *
 * `admitted_recipients` only ever grows: every send the budget admits adds
 * its recipients, in the transaction that inserts it. A transaction whose
 * snapshot predates admissions committed since reads a smaller total through
 * that snapshot than under the lock, and adds the difference to what it
 * counted, so the sends its snapshot cannot see still count. Never reset or
 * delete a row while its workspace exists: the difference would go negative
 * and those sends would stop counting.
 *
 * Tenant-owned (docs/client-management/tenant-foreign-keys.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_email_budget_locks', function (Blueprint $table): void {
            $table->foreignId('workspace_id')->primary()->constrained('workspaces')->cascadeOnDelete();
            $table->unsignedBigInteger('admitted_recipients')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_email_budget_locks');
    }
};
