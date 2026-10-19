<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `(workspace_id, updated_at, id)` on the three tables the change feeds page
 * through: payments (`finance.invoice_payments.list`), invoices and client
 * companies. Each feed filters one workspace, bounds `updated_at` by the
 * pass's window and orders by `updated_at desc, id desc`; without this index
 * every page sorts the workspace's whole table. No existing index has
 * `updated_at` in it.
 *
 * Named explicitly: the generated names are long, and MariaDB refuses an
 * identifier over 64 characters.
 */
return new class extends Migration
{
    /** @var array<string, string> table => index name */
    private const INDEXES = [
        'client_companies' => 'client_companies_ws_updated_id_index',
        'client_invoices' => 'client_invoices_ws_updated_id_index',
        'client_invoice_payments' => 'client_invoice_payments_ws_updated_id_index',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $name) {
            Schema::table($table, function (Blueprint $blueprint) use ($name): void {
                $blueprint->index(['workspace_id', 'updated_at', 'id'], $name);
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $name) {
            Schema::table($table, function (Blueprint $blueprint) use ($name): void {
                $blueprint->dropIndex($name);
            });
        }
    }
};
