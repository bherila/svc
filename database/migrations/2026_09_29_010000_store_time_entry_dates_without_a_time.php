<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Time entries written on SQLite before `DateOnly` store `Y-m-d 00:00:00`, and a
 * date-string bound leaves those rows out on the last day of a range (#354).
 * MariaDB's `DATE` column never held a time, so this changes nothing there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        DB::table('client_time_entries')
            ->whereRaw('length(worked_on) > 10')
            ->update(['worked_on' => DB::raw('substr(worked_on, 1, 10)')]);
    }

    public function down(): void
    {
        // The time was always midnight and meant nothing; there is nothing to restore.
    }
};
