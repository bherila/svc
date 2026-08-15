<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_invoices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_company_id')->constrained()->restrictOnDelete();
            // Engagement migrations own this optional relationship, so this slice deliberately has no cross-migration FK.
            $table->unsignedBigInteger('client_agreement_id')->nullable();
            $table->unsignedBigInteger('client_billing_schedule_id')->nullable();
            $table->string('invoice_number', 80);
            $table->string('status', 32)->default('draft');
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('service_period_start')->nullable();
            $table->date('service_period_end')->nullable();
            $table->char('currency', 3);
            $table->unsignedBigInteger('subtotal_amount')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('total_amount')->default(0);
            $table->unsignedBigInteger('paid_amount')->default(0);
            $table->unsignedBigInteger('balance_amount')->default(0);
            $table->text('notes')->nullable();
            $table->boolean('is_visible_to_client')->default(false);
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'invoice_number']);
            $table->unique([
                'client_billing_schedule_id',
                'service_period_start',
                'service_period_end',
            ], 'billing_schedule_service_period_unique');
            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'client_company_id']);
        });

        Schema::create('client_invoice_lines', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_invoice_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->text('description');
            $table->decimal('quantity', 16, 4);
            $table->bigInteger('unit_amount');
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->bigInteger('total_amount');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['workspace_id', 'client_invoice_id']);
        });

        Schema::create('client_invoice_line_time_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_invoice_line_id')->constrained()->cascadeOnDelete();
            // client_time_entries is supplied by the engagement slice and may migrate independently.
            $table->unsignedBigInteger('client_time_entry_id');
            $table->timestamps();
            $table->unique(['workspace_id', 'client_time_entry_id'], 'invoice_time_entry_once');
            $table->unique(['client_invoice_line_id', 'client_time_entry_id'], 'invoice_line_time_entry_unique');
        });

        Schema::create('client_invoice_payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_invoice_id')->constrained()->cascadeOnDelete();
            $table->string('status', 24)->default('pending');
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('refunded_amount')->default(0);
            $table->char('currency', 3);
            $table->date('received_on')->nullable();
            $table->string('method', 40);
            $table->string('reference', 255)->nullable();
            $table->text('notes')->nullable();
            $table->string('provider', 40)->nullable();
            $table->string('provider_payment_identifier', 255)->nullable();
            $table->uuid('external_finance_transaction_uuid')->nullable();
            $table->string('idempotency_key', 255)->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'idempotency_key'], 'payment_idempotency_unique');
            $table->unique(['provider', 'provider_payment_identifier'], 'provider_payment_unique');
            $table->index(['workspace_id', 'client_invoice_id', 'status'], 'cip_workspace_invoice_status_idx');
        });

        Schema::create('client_billing_schedules', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('client_agreement_id');
            $table->string('cadence', 24);
            $table->unsignedTinyInteger('anchor_month')->nullable();
            $table->unsignedTinyInteger('anchor_day')->nullable();
            $table->date('next_run_on');
            $table->unsignedInteger('due_days')->default(0);
            $table->char('currency', 3);
            $table->boolean('is_active')->default(true);
            $table->json('line_template');
            $table->timestamps();
            $table->index(['workspace_id', 'is_active', 'next_run_on'], 'cbs_workspace_active_next_run_idx');
            $table->unique(['workspace_id', 'client_agreement_id'], 'billing_schedule_agreement_unique');
        });

        Schema::create('client_invoice_email_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_invoice_id')->constrained()->cascadeOnDelete();
            $table->json('recipients');
            $table->string('subject', 255);
            $table->string('status', 24)->default('pending');
            $table->string('provider_message_reference', 255)->nullable();
            $table->string('error_summary', 1000)->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'client_invoice_id', 'status'], 'cied_workspace_invoice_status_idx');
        });

        Schema::create('client_stripe_customers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_company_id')->constrained()->restrictOnDelete();
            $table->string('stripe_customer_id', 255)->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'client_company_id']);
        });

        Schema::create('client_stripe_payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_company_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_stripe_customer_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_payment_method_id', 255)->unique();
            $table->string('type', 40);
            $table->string('brand', 40)->nullable();
            $table->string('last4', 4)->nullable();
            $table->unsignedTinyInteger('exp_month')->nullable();
            $table->unsignedSmallInteger('exp_year')->nullable();
            $table->boolean('is_default')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'client_company_id'], 'cspm_workspace_company_idx');
        });

        Schema::create('client_stripe_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('stripe_event_id', 255)->unique();
            $table->string('event_type', 120);
            $table->string('object_id', 255)->nullable();
            $table->string('payload_hash', 64);
            $table->string('status', 24)->default('received');
            $table->string('error_summary', 1000)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_stripe_events');
        Schema::dropIfExists('client_stripe_payment_methods');
        Schema::dropIfExists('client_stripe_customers');
        Schema::dropIfExists('client_invoice_email_deliveries');
        Schema::dropIfExists('client_billing_schedules');
        Schema::dropIfExists('client_invoice_payments');
        Schema::dropIfExists('client_invoice_line_time_entries');
        Schema::dropIfExists('client_invoice_lines');
        Schema::dropIfExists('client_invoices');
    }
};
