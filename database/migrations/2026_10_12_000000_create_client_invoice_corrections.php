<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_invoice_corrections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('client_invoice_id');
            $table->unsignedInteger('from_revision');
            $table->unsignedInteger('to_revision');
            $table->text('reason');
            $table->date('previous_due_date')->nullable();
            $table->date('due_date')->nullable();
            $table->json('line_diffs');
            $table->timestamps();
            $table->unique(['workspace_id', 'client_invoice_id', 'to_revision'], 'invoice_correction_revision_unique');
            $table->foreign(['workspace_id', 'client_invoice_id'], 'invoice_correction_tenant_fk')
                ->references(['workspace_id', 'id'])->on('client_invoices')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_invoice_corrections');
    }
};
