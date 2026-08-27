<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let `rollover_months` hold -1, meaning hours never expire.
 *
 * The column was `unsignedInteger`, so the sentinel could not be stored at all.
 * That is the same trap that hid the negative-balance defect: MySQL refuses the
 * write outright and SQLite accepts it, so a test suite on SQLite would have
 * reported the feature working while production rejected every attempt to
 * configure it.
 *
 * Widening rather than adding a separate boolean, because the question
 * "how long do unused hours survive?" has one answer and should live in one
 * column. -1 is the only negative value with a meaning; anything else negative
 * is treated as unlimited too rather than being given a second interpretation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_agreements', function (Blueprint $table): void {
            $table->integer('rollover_months')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        // Anything that was using the sentinel loses it. Unlimited becomes the
        // longest window the old column could describe rather than silently
        // becoming none, because reverting a migration must not start expiring
        // hours a client was told would keep.
        DB::table('client_agreements')
            ->where('rollover_months', '<', 0)
            ->update(['rollover_months' => 1200]);

        Schema::table('client_agreements', function (Blueprint $table): void {
            $table->unsignedInteger('rollover_months')->nullable()->default(null)->change();
        });
    }
};
