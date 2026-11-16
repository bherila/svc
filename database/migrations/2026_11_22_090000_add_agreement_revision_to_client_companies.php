<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A counter every write that can make one of a company's agreements overlap
 * another advances, under the company row lock, in the same transaction as
 * the change: an activation, a date edit on an active agreement, an undone
 * scheduled end, a proposal accepted into an active agreement (#718).
 *
 * The overlap check is an ordinary read, so it sees its transaction's
 * snapshot, which a caller can have fixed before the company lock - the agent
 * API's executor reads the agreement first. The check reads this counter
 * twice, through the snapshot and through the lock, and refuses when they
 * differ. See docs/client-management/concurrency.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_companies', function (Blueprint $table): void {
            $table->unsignedBigInteger('agreement_revision')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('client_companies', function (Blueprint $table): void {
            $table->dropColumn('agreement_revision');
        });
    }
};
