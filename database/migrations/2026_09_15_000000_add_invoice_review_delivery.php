<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_companies', function (Blueprint $table): void {
            $table->boolean('automatic_invoice_email_enabled')->default(false)->after('billing_email');
            $table->unsignedSmallInteger('automatic_invoice_email_delay_days')->nullable()->after('automatic_invoice_email_enabled');
        });

        Schema::table('client_invoices', function (Blueprint $table): void {
            $table->unsignedInteger('document_revision')->default(0)->after('status');
            $table->string('automatic_delivery_status', 32)->nullable()->after('issued_at');
            $table->unsignedSmallInteger('automatic_delivery_delay_days')->nullable()->after('automatic_delivery_status');
            $table->timestamp('automatic_delivery_due_at')->nullable()->after('automatic_delivery_delay_days');
            $table->timestamp('automatic_delivery_held_at')->nullable()->after('automatic_delivery_due_at');
            $table->string('automatic_delivery_note', 500)->nullable()->after('automatic_delivery_held_at');
            $table->index(
                ['automatic_delivery_status', 'automatic_delivery_due_at', 'id'],
                'ci_automatic_delivery_due_idx',
            );
        });

        Schema::table('client_invoice_email_deliveries', function (Blueprint $table): void {
            $table->string('origin', 24)->default('manual')->after('client_invoice_id');
            $table->unsignedInteger('invoice_revision')->default(0)->after('origin');
            $table->unsignedSmallInteger('attempt_number')->default(1)->after('invoice_revision');
            // Match the existing browser, API and MCP contract: callers own
            // stable opaque keys, which are not required to be UUIDs.
            $table->string('idempotency_key', 255)->nullable()->after('attempt_number');
            $table->timestamp('claimed_at')->nullable()->after('queued_at');
            $table->timestamp('next_attempt_at')->nullable()->after('failed_at');
            $table->index(
                ['workspace_id', 'origin', 'status', 'next_attempt_at'],
                'cied_dispatch_due_idx',
            );
            $table->unique(['workspace_id', 'idempotency_key'], 'cied_idempotency_unique');
        });

        Schema::create('client_invoice_administrator_notifications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('invoice_revision');
            $table->string('recipient', 255)->nullable();
            $table->string('subject', 255);
            $table->string('open_url', 2048);
            $table->string('client_name', 160);
            $table->string('invoice_number', 80);
            $table->char('currency', 3);
            $table->unsignedBigInteger('total_amount');
            $table->string('pdf_filename', 255)->nullable();
            // Base64 keeps binary PDF bytes valid under a utf8mb4 connection
            // while retaining the exact issued document for later retries.
            $table->longText('pdf_content_base64')->nullable();
            $table->string('status', 32)->default('pending');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->json('attempt_history')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('provider_message_reference', 255)->nullable();
            $table->string('error_summary', 1000)->nullable();
            $table->timestamps();
            $table->unique(['client_invoice_id', 'invoice_revision'], 'cian_invoice_revision_unique');
            $table->index(['status', 'next_attempt_at', 'id'], 'cian_dispatch_due_idx');
            $table->foreign(['workspace_id', 'client_invoice_id'], 'cian_ws_invoice_fk')
                ->references(['workspace_id', 'id'])->on('client_invoices')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_invoice_administrator_notifications');

        Schema::table('client_invoice_email_deliveries', function (Blueprint $table): void {
            $table->dropIndex('cied_dispatch_due_idx');
            $table->dropUnique('cied_idempotency_unique');
            $table->dropColumn(['origin', 'invoice_revision', 'attempt_number', 'idempotency_key', 'claimed_at', 'next_attempt_at']);
        });

        Schema::table('client_invoices', function (Blueprint $table): void {
            $table->dropIndex('ci_automatic_delivery_due_idx');
            $table->dropColumn([
                'document_revision', 'automatic_delivery_status', 'automatic_delivery_delay_days',
                'automatic_delivery_due_at', 'automatic_delivery_held_at', 'automatic_delivery_note',
            ]);
        });

        Schema::table('client_companies', function (Blueprint $table): void {
            $table->dropColumn(['automatic_invoice_email_enabled', 'automatic_invoice_email_delay_days']);
        });
    }
};
