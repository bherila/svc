<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A counter every writer that can shrink a company's overpayment-credit pool
 * advances, under the company row lock, in the same transaction as the change.
 *
 * `issue()` reads it twice - once through its transaction's snapshot, once
 * through the company lock - and refuses to spend credit when the two differ,
 * because then the ordinary reads the credit ledger is built from may predate
 * a spend or refund that has since committed. See
 * docs/client-management/concurrency.md, "Spending overpayment credit".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_companies', function (Blueprint $table): void {
            $table->unsignedBigInteger('credit_revision')->default(0)->after('automatic_invoice_email_delay_days');
        });
    }

    public function down(): void
    {
        Schema::table('client_companies', function (Blueprint $table): void {
            $table->dropColumn('credit_revision');
        });
    }
};
