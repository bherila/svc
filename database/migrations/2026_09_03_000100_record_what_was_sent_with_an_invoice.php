<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record what an invoice email actually said, and what became of it.
 *
 * The table already held recipients, a subject and a three-state status. That
 * was enough while "Send to client" was one button with no options behind it
 * and no way to find out whether the mail arrived. It is not enough now:
 *
 * - `bcc` because an operator sending an invoice usually wants a copy, and a
 *   blind copy that is not recorded is a copy nobody can prove was sent.
 * - `body` because the covering note is now editable, so the row has to hold
 *   what was said rather than implying it from a template that may since have
 *   changed. A dispute about an invoice is a dispute about the words around it
 *   as often as the figures.
 * - `provider_status` and `provider_status_at` because our own `status` can
 *   only ever say we handed the message to Brevo. Delivered, bounced, blocked
 *   and marked-as-spam are facts only the provider knows, and they arrive later
 *   over a webhook. Keeping them in a separate column is deliberate: conflating
 *   "we sent it" with "they received it" is how an operator ends up certain an
 *   invoice was delivered because a button went green.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_invoice_email_deliveries', function (Blueprint $table): void {
            $table->json('bcc')->nullable()->after('recipients');
            $table->text('body')->nullable()->after('subject');
            $table->string('provider_status', 40)->nullable()->after('provider_message_reference');
            $table->timestamp('provider_status_at')->nullable()->after('provider_status');
        });
    }

    public function down(): void
    {
        Schema::table('client_invoice_email_deliveries', function (Blueprint $table): void {
            $table->dropColumn(['bcc', 'body', 'provider_status', 'provider_status_at']);
        });
    }
};
