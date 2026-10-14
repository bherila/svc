<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        Schema::table('client_invoice_payments', function (Blueprint $table): void {
            $table->string('provider_payment_identifier', 255)->nullable()->collation('utf8mb4_bin')->change();
        });
    }

    public function down(): void
    {
        // Restoring an insensitive unique index could collapse distinct opaque IDs.
        // Binary comparison remains compatible with earlier adapter code.
    }
};
