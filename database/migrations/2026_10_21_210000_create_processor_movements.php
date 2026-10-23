<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Authoritative processor ledger movements; original payments are unchanged. */
return new class extends Migration
{
    public function up(): void
    {
        $binary = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
        Schema::create('processor_movements', function (Blueprint $table) use ($binary): void {
            $table->id();
            $table->uuid('public_id')->unique('pm_public_unique');
            $table->foreignId('workspace_id')->constrained('workspaces', 'id', 'pm_ws_fk')->cascadeOnDelete();
            $table->unsignedBigInteger('client_invoice_payment_id');
            $table->string('provider', 24);
            foreach (['account_key', 'provider_entry_identifier', 'source_identifier', 'provider_payout_identifier'] as $column) {
                $definition = $table->string($column, 255);
                if (in_array($column, ['source_identifier', 'provider_payout_identifier'], true)) {
                    $definition->nullable();
                }
                if ($binary) {
                    $definition->collation('utf8mb4_bin');
                }
            }
            $table->string('kind', 24);
            $table->bigInteger('principal_minor');
            $table->bigInteger('fee_minor');
            $table->bigInteger('net_minor');
            $table->char('currency', 3);
            $table->dateTime('provider_occurred_at', 6);
            $table->date('occurred_on');
            $table->string('occurred_timezone', 64);
            $table->string('date_basis', 32);
            $table->dateTime('available_on', 6)->nullable();
            $table->boolean('needs_review')->default(false);
            $table->string('review_reason', 40)->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            // dateTime avoids MariaDB's implicit first-TIMESTAMP update defaults.
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['workspace_id', 'provider', 'account_key', 'provider_entry_identifier'], 'pm_entry_unique');
            $table->index(['workspace_id', 'updated_at', 'id'], 'pm_ws_updated_idx');
            $table->index(['workspace_id', 'occurred_on', 'id'], 'pm_ws_occurred_idx');
            $table->index(['workspace_id', 'client_invoice_payment_id'], 'pm_ws_payment_idx');
            $table->foreign(['workspace_id', 'client_invoice_payment_id'], 'pm_ws_payment_fk')
                ->references(['workspace_id', 'id'])->on('client_invoice_payments')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processor_movements');
    }
};
