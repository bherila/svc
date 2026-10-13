<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $binary = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
        Schema::table('company_square_customers', function (Blueprint $table) use ($binary): void {
            $customer = $table->string('square_customer_id', 192)->change();
            $slot = $table->string('active_customer_slot', 192)->nullable()->change();
            if ($binary) {
                $customer->collation('utf8mb4_bin');
                $slot->collation('utf8mb4_bin');
            }
        });
    }

    public function down(): void
    {
        // Wider columns remain compatible with prior code. Shrinking them
        // could truncate identifiers imported since this migration ran.
    }
};
