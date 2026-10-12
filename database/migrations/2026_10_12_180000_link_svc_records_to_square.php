<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\Schema;

/**
 * Where an SVC record meets its Square counterpart (#438).
 *
 * SVC stays the system of record; Square identifiers are held here as opaque,
 * format-checked strings and nothing in this migration talks to Square.
 *
 * "One active link per side" cannot be a partial unique index - MariaDB has
 * none - so each table carries `active_*` slot columns: they hold the linked
 * value while the row is active and are cleared when it is unlinked or
 * cleared. A unique index over a nullable column admits any number of nulls,
 * so the retired rows stay as history while the live one stays unique, on both
 * engines, under concurrency.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Square ids are case-sensitive and opaque. The application compares
        // them exactly, so the database has to as well: under the default
        // case-insensitive collation `ABC` and `abc` would be one id to the
        // unique index and two to the code. SQLite already compares bytes.
        $exact = static function (ColumnDefinition $column): ColumnDefinition {
            return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)
                ? $column->collation('utf8mb4_bin')
                : $column;
        };

        Schema::create('company_square_customers', function (Blueprint $table) use ($exact): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('client_company_id');
            $exact($table->string('square_customer_id', 191));
            $table->unsignedBigInteger('active_company_slot')->nullable();
            $exact($table->string('active_customer_slot', 191)->nullable());
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users', 'id', 'csqc_confirmed_by_fk')->nullOnDelete();
            $table->timestamp('confirmed_at');
            $table->foreignId('cleared_by_user_id')->nullable()->constrained('users', 'id', 'csqc_cleared_by_fk')->nullOnDelete();
            $table->timestamp('cleared_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'active_company_slot'], 'csqc_active_company_unique');
            $table->unique(['workspace_id', 'active_customer_slot'], 'csqc_active_customer_unique');
            $table->index(['workspace_id', 'client_company_id'], 'csqc_ws_company_idx');
            $table->foreign(['workspace_id', 'client_company_id'], 'csqc_ws_company_fk')
                ->references(['workspace_id', 'id'])->on('client_companies')->cascadeOnDelete();
        });

        Schema::create('invoice_square_links', function (Blueprint $table) use ($exact): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('client_invoice_id');
            $exact($table->string('square_invoice_id', 191));
            $table->string('square_invoice_number', 191);
            $table->char('series_key', 6)->nullable();
            $table->char('currency', 3);
            $table->string('match_method', 16);
            $table->unsignedTinyInteger('score')->nullable();
            $table->json('reasons')->nullable();
            $table->unsignedBigInteger('active_invoice_slot')->nullable();
            $exact($table->string('active_square_invoice_slot', 191)->nullable());
            $table->foreignId('linked_by_user_id')->nullable()->constrained('users', 'id', 'isql_linked_by_fk')->nullOnDelete();
            $table->timestamp('linked_at');
            $table->foreignId('unlinked_by_user_id')->nullable()->constrained('users', 'id', 'isql_unlinked_by_fk')->nullOnDelete();
            $table->timestamp('unlinked_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'active_invoice_slot'], 'isql_active_invoice_unique');
            $table->unique(['workspace_id', 'active_square_invoice_slot'], 'isql_active_square_unique');
            $table->index(['workspace_id', 'client_invoice_id'], 'isql_ws_invoice_idx');
            $table->index(['workspace_id', 'square_invoice_id'], 'isql_ws_square_idx');
            $table->foreign(['workspace_id', 'client_invoice_id'], 'isql_ws_invoice_fk')
                ->references(['workspace_id', 'id'])->on('client_invoices')->cascadeOnDelete();
        });

        // The "billed in Square" marker: the recurring series' number prefix.
        // While it is set, SVC may still generate and issue the agreement's
        // invoices, but never collects them - Square does.
        Schema::table('client_agreements', function (Blueprint $table): void {
            $table->char('square_series_key', 6)->nullable();
            $table->unique(['workspace_id', 'square_series_key'], 'ca_ws_square_series_unique');
        });
    }

    public function down(): void
    {
        Schema::table('client_agreements', function (Blueprint $table): void {
            $table->dropUnique('ca_ws_square_series_unique');
            $table->dropColumn('square_series_key');
        });
        Schema::dropIfExists('invoice_square_links');
        Schema::dropIfExists('company_square_customers');
    }
};
