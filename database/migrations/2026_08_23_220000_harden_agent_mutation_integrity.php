<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_mutation_receipts', function (Blueprint $table): void {
            $table->foreignId('workspace_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->string('status', 20)->default('completed')->after('request_digest');
            $table->timestamp('completed_at')->nullable()->after('result_public_ids');
        });

        Schema::table('agent_mutation_audits', function (Blueprint $table): void {
            $table->string('error_category', 40)->nullable()->after('outcome');
        });

        Schema::table('client_invoices', function (Blueprint $table): void {
            $table->text('void_reason')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('client_invoices', function (Blueprint $table): void {
            $table->dropColumn('void_reason');
        });

        Schema::table('agent_mutation_audits', function (Blueprint $table): void {
            $table->dropColumn('error_category');
        });

        Schema::table('agent_mutation_receipts', function (Blueprint $table): void {
            $table->dropForeign(['workspace_id']);
            $table->dropColumn(['workspace_id', 'status', 'completed_at']);
        });
    }
};
