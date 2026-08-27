<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give every hour column on an invoice the same precision.
 *
 * The restored closing balances landed as `decimal(8,2)` and the opening
 * balances added a day later as `decimal(12,4)`. They describe the same carried
 * figure from opposite ends of a cycle, so a period closing with 1.3333 unused
 * hours stored `1.33` while the next invoice's `starting_unused_hours` stored
 * `1.3333`, and the two disagreed by a third of an hour that nobody could
 * account for.
 *
 * The first migration's own note argued fractional hours must survive exactly;
 * this makes that true of all of them. Widening only ever adds room, so stored
 * values are unaffected - though figures already rounded to two places stay
 * rounded, which is history and cannot be recovered here.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const COLUMNS = [
        'retainer_hours_included',
        'hours_worked',
        'rollover_hours_used',
        'unused_hours_balance',
        'negative_hours_balance',
        'hours_billed_at_rate',
    ];

    public function up(): void
    {
        Schema::table('client_invoices', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column) {
                $table->decimal($column, 12, 4)->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('client_invoices', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column) {
                $table->decimal($column, 8, 2)->nullable()->change();
            }
        });
    }
};
