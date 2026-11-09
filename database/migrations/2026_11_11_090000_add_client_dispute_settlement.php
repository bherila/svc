<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The provider's answer to a client's dispute (docs/ux-overhaul.md 7b″).
 *
 * A dispute holds a time entry or an expense out of billing until the provider
 * settles it, in one of two ways (`App\Support\Approval\DisputeSettlement`):
 * answered - the dispute is cleared and the row is offered to the client again
 * - or written off - it is never billed. Either way the reply is kept for the
 * client to read, with who settled it and when. A new dispute clears them.
 *
 * Constraint names are left to the framework's `{table}_{column}_foreign`
 * pattern, the longest of which is 61 characters.
 */
return new class extends Migration
{
    private const TABLES = ['client_time_entries', 'client_expenses'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('client_dispute_settlement', 16)->nullable();
                $blueprint->string('client_dispute_reply', 1000)->nullable();
                $blueprint->timestamp('client_dispute_settled_at')->nullable();
                $blueprint->foreignId('client_dispute_settled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropForeign(['client_dispute_settled_by_user_id']);
                $blueprint->dropColumn([
                    'client_dispute_settlement', 'client_dispute_reply', 'client_dispute_settled_at', 'client_dispute_settled_by_user_id',
                ]);
            });
        }
    }
};
