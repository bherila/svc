<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_company_activity', function (Blueprint $table): void {
            $table->uuid('subject_public_id')->nullable()->after('external_subject_id');
            $table->string('deduplication_key', 64)->nullable()->after('subject_public_id');
            $table->index(
                ['workspace_id', 'subject_type', 'subject_public_id'],
                'cca_workspace_subject_public_idx',
            );
            $table->unique(
                ['workspace_id', 'deduplication_key'],
                'cca_workspace_deduplication_unique',
            );
        });

        Schema::table('client_stripe_payment_methods', function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('client_stripe_payment_methods', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });

        Schema::table('client_company_activity', function (Blueprint $table): void {
            $table->dropUnique('cca_workspace_deduplication_unique');
            $table->dropIndex('cca_workspace_subject_public_idx');
            $table->dropColumn(['subject_public_id', 'deduplication_key']);
        });
    }
};
