<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Show time as 2.5 h / 2:30": one per-user display preference.
 *
 * Time is stored as whole minutes regardless; this only decides the words the
 * person reads on every screen and timesheet. Invoices ignore it and render
 * decimal. Default `decimal`, so the previous release (which never reads the
 * column) and this one agree for everyone who has not chosen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('duration_style', 16)->default('decimal');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('duration_style');
        });
    }
};
