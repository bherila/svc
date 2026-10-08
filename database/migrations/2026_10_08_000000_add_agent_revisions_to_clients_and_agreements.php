<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_companies', fn (Blueprint $table) => $table->unsignedBigInteger('lock_version')->default(1));
        Schema::table('client_agreements', fn (Blueprint $table) => $table->unsignedBigInteger('lock_version')->default(1));
    }

    public function down(): void
    {
        Schema::table('client_companies', fn (Blueprint $table) => $table->dropColumn('lock_version'));
        Schema::table('client_agreements', fn (Blueprint $table) => $table->dropColumn('lock_version'));
    }
};
