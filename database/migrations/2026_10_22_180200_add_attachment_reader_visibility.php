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
        // R1 reader compatibility: an older uploader omits this column. Every
        // lifecycle inherits its parent, even if that parent is currently private.
        Schema::table('client_attachments', function (Blueprint $table): void {
            $table->boolean('is_visible_to_client')->default(true);
            $table->enum('record_type', ['company', 'project', 'task', 'proposal', 'agreement', 'invoice', 'expense', 'topic', 'comment'])->change();
        });
    }

    public function down(): void
    {
        // An older reader ignores the flag and cannot authorize the new parents.
        if (DB::table('client_attachments')->where('is_visible_to_client', false)
            ->orWhereIn('record_type', ['topic', 'comment'])->exists() || app(AttachmentPrivacyLatch::class)->enabled()) {
            throw new RuntimeException('Private or collaboration attachments, or an activated privacy latch, require the reader release; rollback would disclose or orphan retained files.');
        }
        Schema::table('client_attachments', function (Blueprint $table): void {
            $table->dropColumn('is_visible_to_client');
            $table->enum('record_type', ['company', 'project', 'task', 'proposal', 'agreement', 'invoice', 'expense'])->change();
        });
    }
};
