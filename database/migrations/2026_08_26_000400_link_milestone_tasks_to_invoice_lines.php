<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records which invoice line billed a milestone task.
 *
 * A task with a milestone price is billed once, when it completes. Without a
 * link back to the line that billed it, every draft regeneration would find the
 * same completed task unbilled and charge for it again.
 *
 * Time entries express this through a pivot because one entry can be split
 * across lines. A milestone cannot be split — it is one deliverable at one
 * price — so a single nullable column is the honest shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_tasks', function (Blueprint $table): void {
            $table->unsignedBigInteger('client_invoice_line_id')->nullable()->after('milestone_price_amount');
            $table->foreign('client_invoice_line_id', 'task_invoice_line_fk')
                ->references('id')->on('client_invoice_lines')->nullOnDelete();
            $table->index(['workspace_id', 'client_invoice_line_id'], 'task_invoice_line_idx');
        });
    }

    public function down(): void
    {
        Schema::table('client_tasks', function (Blueprint $table): void {
            $table->dropForeign('task_invoice_line_fk');
            $table->dropIndex('task_invoice_line_idx');
            $table->dropColumn('client_invoice_line_id');
        });
    }
};
