<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An opaque revision for payments, so a correction can be refused when the row
 * moved after the caller read it. Every existing row starts at 1, which is what
 * `IncrementsAgentRevision` assigns a new one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_invoice_payments', function (Blueprint $table): void {
            $table->unsignedInteger('lock_version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('client_invoice_payments', function (Blueprint $table): void {
            $table->dropColumn('lock_version');
        });
    }
};
