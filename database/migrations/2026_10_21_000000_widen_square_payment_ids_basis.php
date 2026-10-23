<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The payment-id basis now names the Square invoice version as well as its
 * status and paid amount (#489): STATUS|PAID|VERSION|rules[|suffix]. A
 * 32-character status, a 20-digit amount and a 20-digit version need more
 * than the 64 characters the basis had. Every stored basis lacks the version,
 * so each paid invoice's order is read once more on the next sync, as a
 * change of tender rules does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('square_invoice_snapshots', function (Blueprint $table): void {
            $table->string('payment_ids_basis', 128)->nullable()->change();
        });
    }

    /** Rolled back after a sync, a basis may be too long; it is cleared, so the order is read again. */
    public function down(): void
    {
        DB::table('square_invoice_snapshots')->whereRaw('length(payment_ids_basis) > 64')->update(['payment_ids_basis' => null]);
        Schema::table('square_invoice_snapshots', function (Blueprint $table): void {
            $table->string('payment_ids_basis', 64)->nullable()->change();
        });
    }
};
