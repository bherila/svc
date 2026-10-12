<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The day an operator intends to issue a draft (#381).
 *
 * Until now that intention lived only in free-text notes, so nothing could
 * notice it had passed. It is a plan, not a fact: nothing issues or sends on
 * it. The stale-draft audit reads it alongside the due date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_invoices', function (Blueprint $table): void {
            $table->date('scheduled_issue_date')->nullable()->after('due_date');
        });
    }

    public function down(): void
    {
        Schema::table('client_invoices', function (Blueprint $table): void {
            $table->dropColumn('scheduled_issue_date');
        });
    }
};
