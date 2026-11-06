<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The client's own approval of hours and expenses, kept apart from the
 * operator's `status` (UX overhaul section 7b).
 *
 * `approved_by_user_id` and `approved_at` stay what they were: the operator's
 * check, which fixes the rate on time and is the "Ready to bill" gate on an
 * expense. Nothing here rewrites them. A client's say-so is a different fact,
 * made by a different person, so it gets its own columns and no status:
 * the ledgers keep reading `approved` and `invoiced` untouched.
 *
 * - `client_approval_source` is `portal` for a person's explicit approval, or
 *   `payment` when paying the invoice that carries the row approved it. A
 *   payment approval names no person, and it holds only while that invoice
 *   stays paid in full: a refund or a reopen undoes it without a write, which
 *   is why the source is stored and the verdict is read, never cached.
 * - A dispute is a client's statement about one row. It is a note for the
 *   operator, whose way to act on an issued invoice is the correction flow;
 *   it never edits the document.
 *
 * `can_approve_time_and_expenses` on a portal membership is the per-contact
 * grant. Null means "the default": a contact whose address is the client's
 * billing address may approve, anyone else may not. True and false are
 * decisions somebody made.
 *
 * Constraint names are left to the framework's `{table}_{column}_foreign`
 * pattern, the longest of which is 54 characters.
 */
return new class extends Migration
{
    private const TABLES = ['client_time_entries', 'client_expenses'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->timestamp('client_approved_at')->nullable();
                $blueprint->foreignId('client_approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $blueprint->string('client_approval_source', 16)->nullable();
                $blueprint->timestamp('client_disputed_at')->nullable();
                $blueprint->foreignId('client_disputed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $blueprint->string('client_dispute_note', 1000)->nullable();
            });
        }

        Schema::table('client_company_memberships', function (Blueprint $table): void {
            $table->boolean('can_approve_time_and_expenses')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('client_company_memberships', function (Blueprint $table): void {
            $table->dropColumn('can_approve_time_and_expenses');
        });

        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropForeign(['client_approved_by_user_id']);
                $blueprint->dropForeign(['client_disputed_by_user_id']);
                $blueprint->dropColumn([
                    'client_approved_at', 'client_approved_by_user_id', 'client_approval_source',
                    'client_disputed_at', 'client_disputed_by_user_id', 'client_dispute_note',
                ]);
            });
        }
    }
};
