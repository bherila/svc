<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Autopay (#636): SVC charges a client company's default saved Stripe method
 * for an issued invoice on its due date.
 *
 * - `client_companies` carries the current switch and the per-invoice limit.
 *   The switch is the time it was turned on rather than a boolean, like the
 *   reminder cadence, so a turn-on never reaches back to an invoice that fell
 *   due before it.
 * - `client_autopay_consents` is the append-only record of every on, off and
 *   limit change: who, in which role, when, the limit, how consent was given,
 *   and the exact wording or attestation. Nothing updates or deletes a row.
 * - `invoice_autopay_attempts` holds at most one row per invoice: the claim
 *   made before Stripe is called, its outcome, or the provider's decision to
 *   skip the invoice. The unique key on the invoice is the idempotency guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_companies', function (Blueprint $table): void {
            $table->timestamp('autopay_enabled_at')->nullable();
            $table->unsignedBigInteger('autopay_limit_amount')->nullable();
        });

        Schema::create('client_autopay_consents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('client_company_id');
            $table->string('action', 16);
            $table->foreignId('actor_user_id')->nullable()->constrained('users', 'id', 'aco_actor_fk')->nullOnDelete();
            $table->string('actor_name', 191)->nullable();
            $table->string('actor_role', 16);
            $table->string('method', 24);
            $table->unsignedBigInteger('limit_amount')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('payment_method_label', 120)->nullable();
            $table->text('wording')->nullable();
            $table->text('attestation')->nullable();
            $table->string('reason', 191)->nullable();
            $table->boolean('notice_sent')->default(false);
            // A dateTime, not a timestamp: see create_invoice_reminder_logs.
            $table->dateTime('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['workspace_id', 'client_company_id', 'id'], 'aco_ws_company_idx');
            $table->foreign(['workspace_id', 'client_company_id'], 'aco_ws_company_fk')
                ->references(['workspace_id', 'id'])->on('client_companies')->cascadeOnDelete();
        });

        Schema::create('invoice_autopay_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('client_invoice_id');
            $table->unsignedBigInteger('client_company_id');
            $table->string('state', 16);
            $table->bigInteger('amount')->nullable();
            $table->char('currency', 3)->nullable();
            $table->unsignedBigInteger('limit_amount')->nullable();
            $table->uuid('consent_public_id')->nullable();
            $table->string('payment_method_label', 120)->nullable();
            $table->string('payment_intent_id', 255)->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->foreignId('skipped_by_user_id')->nullable()->constrained('users', 'id', 'iaa_skipped_by_fk')->nullOnDelete();
            $table->timestamp('skipped_at')->nullable();
            $table->dateTime('claimed_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // One attempt per invoice, ever: the claim is an insert, and a
            // second claimant finds this key taken.
            $table->unique(['workspace_id', 'client_invoice_id'], 'iaa_invoice_unique');
            $table->index(['workspace_id', 'client_company_id', 'id'], 'iaa_ws_company_idx');
            $table->index(['state', 'claimed_at'], 'iaa_review_idx');
            $table->foreign(['workspace_id', 'client_invoice_id'], 'iaa_ws_invoice_fk')
                ->references(['workspace_id', 'id'])->on('client_invoices')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'client_company_id'], 'iaa_ws_company_fk')
                ->references(['workspace_id', 'id'])->on('client_companies')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_autopay_attempts');
        Schema::dropIfExists('client_autopay_consents');
        Schema::table('client_companies', function (Blueprint $table): void {
            $table->dropColumn(['autopay_enabled_at', 'autopay_limit_amount']);
        });
    }
};
