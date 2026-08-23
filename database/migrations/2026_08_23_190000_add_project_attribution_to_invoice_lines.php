<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_invoice_lines', function (Blueprint $table): void {
            $table->foreignId('client_project_id')->nullable()->after('client_invoice_id')->constrained()->nullOnDelete();
            $table->index(['workspace_id', 'client_project_id']);
        });
    }

    public function down(): void
    {
        Schema::table('client_invoice_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('client_project_id');
        });
    }
};
