<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_comments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('client_company_id');
            $table->unsignedBigInteger('client_project_id')->nullable();
            $table->unsignedBigInteger('client_task_id')->nullable();
            $table->unsignedBigInteger('client_collaboration_topic_id')->nullable();
            $table->mediumText('body');
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_name');
            $table->boolean('is_visible_to_client')->default(false);
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'id'], 'cc_ws_id_unique');
            $table->index(['workspace_id', 'client_task_id', 'id'], 'cc_task_page_idx');
            $table->index(['workspace_id', 'client_collaboration_topic_id', 'id'], 'cc_topic_page_idx');
            $table->foreign(['workspace_id', 'client_company_id'], 'cc_ws_company_fk')->references(['workspace_id', 'id'])->on('client_companies')->restrictOnDelete();
            $table->foreign(['workspace_id', 'client_project_id'], 'cc_ws_project_fk')->references(['workspace_id', 'id'])->on('client_projects')->restrictOnDelete();
            $table->foreign(['workspace_id', 'client_task_id'], 'cc_ws_task_fk')->references(['workspace_id', 'id'])->on('client_tasks')->restrictOnDelete();
            $table->foreign(['workspace_id', 'client_collaboration_topic_id'], 'cc_ws_topic_fk')->references(['workspace_id', 'id'])->on('client_collaboration_topics')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_comments');
    }
};
