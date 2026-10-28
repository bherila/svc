<?php

use App\Services\Files\AttachmentPrivacyLatch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Schema creation also runs before runtime activation on fresh installs/CI.
        // No retained row is rewritten; new writers enforce their runtime latch.
        Schema::table('client_attachments', function (Blueprint $table): void {
            $table->boolean('is_visible_to_client')->default(false)->change();
        });
    }

    public function down(): void
    {
        if (DB::table('client_attachments')->where('is_visible_to_client', false)->orWhereIn('record_type', ['topic', 'comment'])->exists()
            || app(AttachmentPrivacyLatch::class)->enabled()) {
            throw new RuntimeException('Retained private or collaboration attachments, or the privacy latch, require the private-default schema.');
        }
        Schema::table('client_attachments', function (Blueprint $table): void {
            $table->boolean('is_visible_to_client')->default(true)->change();
        });
    }
};
