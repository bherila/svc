<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment reminders (#438): every reminder SVC emails about an open invoice,
 * and the per-client switch for the automatic cadence.
 *
 * A reminder row is written before anything is sent and is the durable claim
 * on it. An automatic reminder carries its cadence step, and
 * `(workspace_id, client_invoice_id, cadence_step)` is unique: a retry, a
 * second scheduler or a concurrent run finds the row and sends nothing. A
 * manual reminder has no step, and a unique index admits any number of nulls,
 * so a person may remind as often as they decide to.
 *
 * The cadence is off until a client is switched on. The switch is the time it
 * was turned on rather than a boolean, so a step that fell due before then is
 * never sent: enabling a client does not email its whole overdue backlog.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_companies', function (Blueprint $table): void {
            $table->timestamp('payment_reminders_enabled_at')->nullable();
        });

        Schema::create('invoice_reminder_logs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('client_invoice_id');
            $table->string('origin', 16);
            $table->string('cadence_step', 16)->nullable();
            $table->string('channel', 16);
            $table->string('pay_link', 16);
            $table->string('square_invoice_number', 191)->nullable();
            $table->json('recipients');
            $table->string('subject', 255);
            $table->char('currency', 3);
            $table->bigInteger('amount_due');
            $table->date('due_on')->nullable();
            $table->string('status', 16);
            $table->unsignedTinyInteger('attempt_number')->default(1);
            $table->string('triggered_by', 16);
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users', 'id', 'irl_triggered_by_fk')->nullOnDelete();
            $table->string('oauth_client_id', 100)->nullable();
            $table->string('provider_message_reference', 255)->nullable();
            $table->string('error_summary', 255)->nullable();
            $table->timestamp('queued_at');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'client_invoice_id', 'cadence_step'], 'irl_step_claim_unique');
            $table->index(['workspace_id', 'client_invoice_id', 'id'], 'irl_ws_invoice_idx');
            $table->index(['status', 'next_attempt_at'], 'irl_retry_idx');
            $table->foreign(['workspace_id', 'client_invoice_id'], 'irl_ws_invoice_fk')
                ->references(['workspace_id', 'id'])->on('client_invoices')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_reminder_logs');
        Schema::table('client_companies', function (Blueprint $table): void {
            $table->dropColumn('payment_reminders_enabled_at');
        });
    }
};
