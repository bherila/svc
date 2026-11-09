<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last result of automatic recurring invoicing on an enrollment
 * (docs/service-packages.md, slice 4: INV-1, INV-3).
 *
 * `svc:packages:issue-invoices` records here what it last did for the
 * enrollment's agreement, so an owner sees a refusal (Needs you, and
 * `enrollments.*`) instead of a log line nobody reads:
 *
 * - `auto_invoice_outcome`: `issued`, `nothing_to_bill` or `refused`.
 * - `auto_invoice_period_start`: the first day of the billing period it was for.
 * - `auto_invoice_refusal`, `auto_invoice_detail`: why it was refused, as a
 *   code and the refusal's own words; null unless refused.
 * - `auto_invoice_id`: the invoice it issued; null unless issued. A composite
 *   key keeps it inside the enrollment's workspace, restricted on delete like
 *   the sign-up invoice: an issued invoice is never deleted.
 * - `auto_invoice_recorded_at`: when this result was first recorded. A repeat
 *   of the same result changes nothing, so the row's version is not churned
 *   by every hourly run.
 *
 * Schema only; nothing here writes data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_package_enrollments', function (Blueprint $table): void {
            $table->string('auto_invoice_outcome', 32)->nullable()->after('lapsed_at');
            $table->date('auto_invoice_period_start')->nullable()->after('auto_invoice_outcome');
            $table->string('auto_invoice_refusal', 64)->nullable()->after('auto_invoice_period_start');
            $table->string('auto_invoice_detail', 500)->nullable()->after('auto_invoice_refusal');
            $table->unsignedBigInteger('auto_invoice_id')->nullable()->after('auto_invoice_detail');
            $table->timestamp('auto_invoice_recorded_at')->nullable()->after('auto_invoice_id');
            // Named, so the rollback can drop it: InnoDB does not drop the index
            // it would otherwise create to serve the key below.
            $table->index(['workspace_id', 'auto_invoice_id'], 'svpke_ws_auto_invoice_idx');
            $table->foreign(['workspace_id', 'auto_invoice_id'], 'svpke_ws_auto_invoice_fk')
                ->references(['workspace_id', 'id'])->on('client_invoices')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $sqlite = Schema::getConnection()->getDriverName() === 'sqlite';
        Schema::table('service_package_enrollments', function (Blueprint $table) use ($sqlite): void {
            $table->dropForeign($sqlite ? ['workspace_id', 'auto_invoice_id'] : 'svpke_ws_auto_invoice_fk');
            $table->dropIndex('svpke_ws_auto_invoice_idx');
            $table->dropColumn(['auto_invoice_outcome', 'auto_invoice_period_start', 'auto_invoice_refusal', 'auto_invoice_detail', 'auto_invoice_id', 'auto_invoice_recorded_at']);
        });
    }
};
