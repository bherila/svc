<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $binary = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
        Schema::create('square_pending_settlements', function (Blueprint $table) use ($binary): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            foreach (['payment_identifier', 'payout_identifier'] as $column) {
                $identifier = $table->string($column, 255);
                if ($binary) {
                    $identifier->collation('utf8mb4_bin');
                }
            }
            $table->date('arrival_date')->nullable();
            $table->bigInteger('fee_amount')->nullable();
            $table->char('fee_currency', 3)->nullable();
            $table->unsignedBigInteger('reported_at_ms');
            $table->dateTime('received_at');
            $table->unique(['workspace_id', 'payment_identifier'], 'square_pending_ws_payment_unique');
            $table->index(['workspace_id', 'received_at', 'id'], 'square_pending_retention_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('square_pending_settlements');
    }
};
