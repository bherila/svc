<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\Schema;

/**
 * A Square invoice "covered by" an svc invoice (#487).
 *
 * The link stays one-to-one (owner decision, 2026-10-12): when one svc
 * invoice covers what Square split into several, the primary pair is linked
 * and each other Square invoice is recorded here as covered by that svc
 * invoice, so the billing audit stops reporting it as an unmirrored period.
 * A coverage is not a link: nothing collects, reconciles or records payments
 * through it.
 *
 * One active coverage per Square invoice, held by the same nullable slot
 * column and unique index as a link's; a cleared coverage stays as history.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exact = static function (ColumnDefinition $column): ColumnDefinition {
            return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)
                ? $column->collation('utf8mb4_bin')
                : $column;
        };

        Schema::create('square_invoice_coverages', function (Blueprint $table) use ($exact): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            // The svc invoice that covers the Square invoice.
            $table->unsignedBigInteger('client_invoice_id');
            $exact($table->string('square_invoice_id', 191));
            $table->string('square_invoice_number', 191);
            $exact($table->string('active_square_invoice_slot', 191)->nullable());
            $table->foreignId('covered_by_user_id')->nullable()->constrained('users', 'id', 'sqic_covered_by_fk')->nullOnDelete();
            $table->timestamp('covered_at');
            $table->foreignId('cleared_by_user_id')->nullable()->constrained('users', 'id', 'sqic_cleared_by_fk')->nullOnDelete();
            $table->timestamp('cleared_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'active_square_invoice_slot'], 'sqic_active_square_unique');
            $table->index(['workspace_id', 'client_invoice_id'], 'sqic_ws_invoice_idx');
            $table->foreign(['workspace_id', 'client_invoice_id'], 'sqic_ws_invoice_fk')
                ->references(['workspace_id', 'id'])->on('client_invoices')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('square_invoice_coverages');
    }
};
