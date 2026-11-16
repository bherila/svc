<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A company's agreement revision (#718): a counter every write that can make
 * two of its agreements overlap advances, under the company row lock, in the
 * same transaction as the change - an activation, a date edit on an active
 * agreement, an undone scheduled end of an active agreement, a proposal
 * accepted into an active agreement.
 *
 * The overlap and active-agreement checks are ordinary reads, so they see
 * their transaction's snapshot, which a caller can have fixed before the
 * company lock - the agent API's executor reads the agreement first. Under the
 * lock the check reads this row twice, with a lock and through the snapshot,
 * and refuses when they differ.
 *
 * A table of its own rather than a column on the company, because a
 * transaction that has written the company row - a credit spend advancing
 * `credit_revision` - reads that row's current version through its snapshot,
 * and a revision kept there would then match whatever the snapshot's age.
 * Nothing but the writes above touches this row, and each follows its own
 * check. A missing row is revision zero. Never reset or delete a row while its
 * company exists: a lower revision could match an older snapshot.
 *
 * Tenant-owned (docs/client-management/tenant-foreign-keys.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_agreement_revisions', function (Blueprint $table): void {
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->unsignedBigInteger('client_company_id');
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestamps();

            $table->primary(['workspace_id', 'client_company_id']);
            $table->foreign(['workspace_id', 'client_company_id'], 'client_agreement_revisions_company_foreign')
                ->references(['workspace_id', 'id'])->on('client_companies')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_agreement_revisions');
    }
};
