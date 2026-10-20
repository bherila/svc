<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_agreements', function (Blueprint $table): void {
            $table->boolean('show_deferred_backlog_to_client')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('client_agreements', function (Blueprint $table): void {
            $table->dropColumn('show_deferred_backlog_to_client');
        });
    }
};
