<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('square_webhook_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->char('event_hash', 64);
            $table->string('event_type', 64);
            $table->uuid('claim_token')->nullable();
            $table->dateTime('lease_expires_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->unique(['workspace_id', 'event_hash'], 'square_webhook_event_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('square_webhook_receipts');
    }
};
