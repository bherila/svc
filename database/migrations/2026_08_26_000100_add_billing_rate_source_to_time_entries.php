<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records where a time entry's billing rate came from.
 *
 * The source system had no per-entry client rate at all — the rate lived on the
 * agreement — so every imported entry arrived with a null rate, and SVC cannot
 * invoice an entry without one. Filling that gap means inferring a money figure,
 * and an inferred figure must be distinguishable from a recorded one.
 *
 * `agreement` means resolved from the agreement in force on the worked-on date.
 * `explicit` means the rate was supplied when the entry was written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_time_entries', function (Blueprint $table): void {
            $table->string('billing_rate_source', 20)->nullable()->after('billing_rate_amount');
        });
    }

    public function down(): void
    {
        Schema::table('client_time_entries', function (Blueprint $table): void {
            $table->dropColumn('billing_rate_source');
        });
    }
};
