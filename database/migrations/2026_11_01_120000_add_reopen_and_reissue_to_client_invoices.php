<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reopen and reissue an issued invoice under its own number (#594).
 *
 * Only nullable columns are added, so code running before this migration
 * ignores them: a deploy that rolls back to the prior release keeps working.
 * `reopened_at` and `reopen_reason` say the invoice was last reopened and why;
 * `reissued_at` says its current issue is a reissue, which the client email and
 * PDF then state. All three keep their values through later transitions, and
 * the issue, reopen and reissue events themselves stay in the activity history.
 * `is_reissue` marks a delivery sent for a reissued invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_invoices', function (Blueprint $table): void {
            $table->timestamp('reopened_at')->nullable()->after('voided_at');
            $table->text('reopen_reason')->nullable()->after('reopened_at');
            $table->timestamp('reissued_at')->nullable()->after('reopen_reason');
        });

        Schema::table('client_invoice_email_deliveries', function (Blueprint $table): void {
            $table->boolean('is_reissue')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('client_invoice_email_deliveries', function (Blueprint $table): void {
            $table->dropColumn('is_reissue');
        });

        Schema::table('client_invoices', function (Blueprint $table): void {
            $table->dropColumn(['reopened_at', 'reopen_reason', 'reissued_at']);
        });
    }
};
