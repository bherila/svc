<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Autopay follow-ups (#641).
 *
 * - `workspaces.stripe_refused_at`: when Stripe last refused this workspace's
 *   own key or account while autopay was charging. The scheduled command skips
 *   the workspace's autopay charges for a while after it, and the provider's
 *   billing cards say the connection needs attention; re-saving the connection
 *   (a newer `connected_at`) or any ordinary Stripe answer retires it. A
 *   dateTime, not a timestamp: see create_invoice_reminder_logs.
 * - `invoice_autopay_attempts.resolved_by_user_id` / `resolution_note`: who
 *   closed out an attempt held for review, after checking Stripe, and why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dateTime('stripe_refused_at')->nullable();
        });

        Schema::table('invoice_autopay_attempts', function (Blueprint $table): void {
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users', 'id', 'iaa_resolved_by_fk')->nullOnDelete();
            $table->string('resolution_note', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_autopay_attempts', function (Blueprint $table): void {
            // SQLite drops a foreign key by its columns, not its name.
            $table->dropForeign(Schema::getConnection()->getDriverName() === 'sqlite' ? ['resolved_by_user_id'] : 'iaa_resolved_by_fk');
            $table->dropColumn(['resolved_by_user_id', 'resolution_note']);
        });
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn('stripe_refused_at');
        });
    }
};
