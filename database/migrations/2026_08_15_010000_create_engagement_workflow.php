<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_time_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('worked_on');
            $table->unsignedInteger('minutes');
            $table->text('description');
            $table->boolean('is_billable')->default(true);
            $table->boolean('is_deferred')->default(false);
            $table->unsignedBigInteger('billing_rate_amount')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('status')->default('draft');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('subcontractor_cost_amount')->nullable();
            $table->char('subcontractor_cost_currency', 3)->nullable();
            $table->json('subcontractor_cost_metadata')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'worked_on']);
            $table->index(['workspace_id', 'status']);
            $table->index(['client_company_id', 'worked_on']);
        });

        Schema::create('client_proposals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->text('terms')->nullable();
            $table->char('currency', 3);
            $table->boolean('is_visible_to_client')->default(false);
            $table->date('valid_until')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('acceptance_signer_name')->nullable();
            $table->string('acceptance_signer_title')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'client_company_id']);
            $table->index(['client_company_id', 'status']);
        });

        Schema::create('client_proposal_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_proposal_id')->constrained()->cascadeOnDelete();
            $table->text('description');
            $table->decimal('quantity', 12, 3);
            $table->unsignedBigInteger('unit_amount');
            $table->string('cadence')->default('one_time');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['workspace_id', 'client_proposal_id']);
            $table->index(['client_proposal_id', 'sort_order']);
        });

        Schema::create('client_agreements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_proposal_id')->nullable()->constrained('client_proposals')->nullOnDelete();
            $table->string('title');
            $table->string('status')->default('draft');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->text('agreement_text')->nullable();
            $table->boolean('is_visible_to_client')->default(false);
            $table->char('currency', 3);
            $table->unsignedBigInteger('hourly_rate_amount')->nullable();
            $table->unsignedBigInteger('retainer_amount')->nullable();
            $table->unsignedInteger('retainer_minutes')->nullable();
            $table->string('billing_cadence')->default('one_time');
            $table->string('rollover_policy')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->foreignId('signed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('signer_name')->nullable();
            $table->string('signer_title')->nullable();
            $table->timestamp('terminated_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'client_company_id']);
            $table->index(['client_company_id', 'is_visible_to_client']);
            $table->unique('source_proposal_id');
        });

        Schema::create('client_agreement_recurring_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_agreement_id')->constrained()->cascadeOnDelete();
            $table->text('description');
            $table->string('cadence')->default('monthly');
            $table->unsignedTinyInteger('anchor_month')->nullable();
            $table->unsignedTinyInteger('anchor_day')->nullable();
            $table->date('effective_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->decimal('quantity', 12, 3)->default(1);
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->boolean('is_taxable')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['workspace_id', 'client_agreement_id'], 'cari_workspace_agreement_idx');
            $table->index(['client_agreement_id', 'is_active'], 'cari_agreement_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_agreement_recurring_items');
        Schema::dropIfExists('client_agreements');
        Schema::dropIfExists('client_proposal_items');
        Schema::dropIfExists('client_proposals');
        Schema::dropIfExists('client_time_entries');
    }
};
