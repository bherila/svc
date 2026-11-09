<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment at sign-up on an enrollment (docs/service-packages.md, slice 3b:
 * ACC-3, ACC-6).
 *
 * - `signup_invoice_id` is the one invoice acceptance issues when the version
 *   charges at sign-up (the setup fee, the first period, or both). Written once,
 *   at acceptance, and never repointed; a composite key keeps it inside the
 *   enrollment's workspace. Restrict on delete: an invoice the client was asked
 *   to pay is evidence and outlives nothing that names it.
 * - `lapsed_at` records when the lapse command ended an enrollment left unpaid
 *   (or an offer left unaccepted) past its lapse date.
 *
 * Schema only; nothing here writes data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_package_enrollments', function (Blueprint $table): void {
            $table->unsignedBigInteger('signup_invoice_id')->nullable()->after('accepted_version_id');
            // Named, so the rollback can drop it: InnoDB does not drop the index
            // it would otherwise create to serve the key below.
            $table->index(['workspace_id', 'signup_invoice_id'], 'svpke_ws_signup_invoice_idx');
            $table->foreign(['workspace_id', 'signup_invoice_id'], 'svpke_ws_signup_invoice_fk')
                ->references(['workspace_id', 'id'])->on('client_invoices')->restrictOnDelete();
            $table->timestamp('lapsed_at')->nullable()->after('activated_by_user_id');
        });
    }

    public function down(): void
    {
        $sqlite = Schema::getConnection()->getDriverName() === 'sqlite';
        Schema::table('service_package_enrollments', function (Blueprint $table) use ($sqlite): void {
            $table->dropForeign($sqlite ? ['workspace_id', 'signup_invoice_id'] : 'svpke_ws_signup_invoice_fk');
            $table->dropIndex('svpke_ws_signup_invoice_idx');
            $table->dropColumn(['signup_invoice_id', 'lapsed_at']);
        });
    }
};
