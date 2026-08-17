<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_attachment_copies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('legacy_migration_item_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_attachment_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->char('source_path_hash', 64);
            $table->char('source_sha256', 64);
            $table->unsignedBigInteger('source_bytes');
            $table->char('destination_object_key_hash', 64);
            $table->timestamp('copied_at');
            $table->timestamps();

            $table->index(['workspace_id', 'copied_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_attachment_copies');
    }
};
