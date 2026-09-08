<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_attachments', function (Blueprint $table): void {
            $table->enum('record_type', ['company', 'project', 'task', 'proposal', 'agreement', 'invoice', 'expense'])->change();
        });
    }

    public function down(): void
    {
        // Schema rollback cannot guess another parent type for a retained blob.
        if (DB::table('client_attachments')->where('record_type', 'expense')->exists()) {
            throw new RuntimeException('Expense attachments must be retained; this migration cannot be rolled back while they exist.');
        }
        Schema::table('client_attachments', function (Blueprint $table): void {
            $table->enum('record_type', ['company', 'project', 'task', 'proposal', 'agreement', 'invoice'])->change();
        });
    }
};
