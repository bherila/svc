<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique('pr_public_id_unique');
            $table->foreignId('workspace_id')
                ->constrained('workspaces', 'id', 'pr_workspace_fk')
                ->cascadeOnDelete();
            $table->foreignId('client_invoice_payment_id')
                ->constrained('client_invoice_payments', 'id', 'pr_payment_fk')
                ->cascadeOnDelete();
            $table->string('external_system_slug', 80);
            $table->uuid('external_transaction_uuid');
            $table->unsignedBigInteger('allocated_amount');
            $table->char('currency', 3);
            $table->date('reconciled_on')->nullable();
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users', 'id', 'pr_creator_fk')
                ->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['workspace_id', 'client_invoice_payment_id', 'external_system_slug', 'external_transaction_uuid'],
                'pr_payment_system_transaction_unique',
            );
            $table->index(
                ['workspace_id', 'external_system_slug', 'external_transaction_uuid'],
                'pr_workspace_system_transaction_idx',
            );
            $table->index(
                ['workspace_id', 'client_invoice_payment_id', 'is_active'],
                'pr_workspace_payment_active_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reconciliations');
    }
};
