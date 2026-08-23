<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table): void {
            $table->dateTime('dynamically_registered_at')->nullable()->after('revoked')->index();
            $table->dateTime('last_used_at')->nullable()->after('dynamically_registered_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table): void {
            $table->dropIndex(['last_used_at']);
            $table->dropIndex(['dynamically_registered_at']);
            $table->dropColumn(['dynamically_registered_at', 'last_used_at']);
        });
    }
};
