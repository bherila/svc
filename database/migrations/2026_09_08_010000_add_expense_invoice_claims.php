<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_expenses', function (Blueprint $table): void {
            $table->unsignedBigInteger('client_invoice_line_id')->nullable();
            $table->unique(['workspace_id', 'client_invoice_line_id'], 'cex_ws_line_unique');
            // Release the claim explicitly before deleting its line. SET NULL
            // would leave an invoiced expense with no recoverable allocation.
            $table->foreign(['workspace_id', 'client_invoice_line_id'], 'cex_ws_line_fk')
                ->references(['workspace_id', 'id'])->on('client_invoice_lines')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('client_expenses', function (Blueprint $table): void {
            $table->dropForeign(Schema::getConnection()->getDriverName() === 'sqlite'
                ? ['workspace_id', 'client_invoice_line_id'] : 'cex_ws_line_fk');
            $table->dropUnique('cex_ws_line_unique');
            $table->dropColumn('client_invoice_line_id');
        });
    }
};
