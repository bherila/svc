<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_expenses', function (Blueprint $table): void {
            $table->unsignedInteger('lock_version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('client_expenses', function (Blueprint $table): void {
            $table->dropColumn('lock_version');
        });
    }
};
