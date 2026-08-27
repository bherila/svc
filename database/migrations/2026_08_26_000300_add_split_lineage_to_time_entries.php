<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records which entry a fragment was split from.
 *
 * Allocating time across capacity pools splits one entry into several. Here a
 * fragment is a real row, because the invoice-line link is a pivot constrained
 * to one line per entry.
 *
 * The predecessor recombined fragments by matching on date, user, description,
 * project and task. That merges two genuinely separate entries whenever a
 * person logs the same description twice against one project on one day, which
 * is ordinary rather than exotic. Lineage makes recombination exact instead of
 * inferred.
 *
 * The column always points at the *root* entry, never an intermediate
 * fragment, so a repeatedly split entry stays one flat group.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_time_entries', function (Blueprint $table): void {
            $table->unsignedBigInteger('split_from_time_entry_id')->nullable()->after('user_id');
            $table->foreign('split_from_time_entry_id', 'time_entry_split_parent_fk')
                ->references('id')->on('client_time_entries')->nullOnDelete();
            $table->index(['workspace_id', 'split_from_time_entry_id'], 'time_entry_split_parent_idx');
        });
    }

    public function down(): void
    {
        Schema::table('client_time_entries', function (Blueprint $table): void {
            $table->dropForeign('time_entry_split_parent_fk');
            $table->dropIndex('time_entry_split_parent_idx');
            $table->dropColumn('split_from_time_entry_id');
        });
    }
};
