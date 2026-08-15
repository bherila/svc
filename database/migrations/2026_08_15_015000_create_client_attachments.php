<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_attachments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->enum('record_type', ['company', 'project', 'task', 'proposal', 'agreement', 'invoice']);
            $table->uuid('record_public_id');
            $table->string('object_key', 512)->unique();
            $table->string('staged_object_key', 512)->nullable()->unique();
            $table->text('original_filename');
            $table->string('media_type', 255);
            $table->unsignedBigInteger('bytes');
            $table->char('sha256', 64);
            $table->foreignId('uploader_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('lifecycle_state', ['staged', 'available', 'deleting', 'deleted', 'corrupt'])->default('staged');
            $table->timestamp('available_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'record_type', 'record_public_id']);
            $table->index(['lifecycle_state', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_attachments');
    }
};
