<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_company_activity', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 120);
            $table->string('subject_type', 255)->nullable();
            $table->unsignedBigInteger('legacy_subject_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'client_company_id', 'created_at'], 'cca_workspace_company_created_idx');
        });

        Schema::table('client_invoice_email_deliveries', function (Blueprint $table): void {
            $table->json('legacy_metadata')->nullable()->after('error_summary');
        });
    }

    public function down(): void
    {
        Schema::table('client_invoice_email_deliveries', function (Blueprint $table): void {
            $table->dropColumn('legacy_metadata');
        });

        Schema::dropIfExists('client_company_activity');
    }
};
