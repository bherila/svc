<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_invoice_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_invoice_id')->constrained()->restrictOnDelete();
            $table->foreign(['workspace_id', 'client_invoice_id'], 'accounting_invoice_ws_invoice_fk')
                ->references(['workspace_id', 'id'])->on('client_invoices')->restrictOnDelete();
            $table->string('source_key', 190);
            $table->date('effective_on')->nullable();
            $table->json('payload');
            $table->timestamps();
            $table->unique(['workspace_id', 'source_key']);
            $table->index(['workspace_id', 'updated_at', 'id'], 'accounting_invoice_sync');
            $table->index(['workspace_id', 'effective_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_invoice_entries');
    }
};
