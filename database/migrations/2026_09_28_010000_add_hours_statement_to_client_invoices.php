<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The hours statement a cadence invoice was generated with, frozen with its
 * lines at issue so a later ledger change cannot rewrite what a client holds.
 * Null for every invoice generated before it existed, and for ad-hoc and
 * interim invoices, which sell no retainer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_invoices', function (Blueprint $table): void {
            $table->json('hours_statement')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('client_invoices', function (Blueprint $table): void {
            $table->dropColumn('hours_statement');
        });
    }
};
