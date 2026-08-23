<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_auth_codes', fn (Blueprint $table) => $table->string('resource_uri')->nullable());
        Schema::table('oauth_access_tokens', fn (Blueprint $table) => $table->string('resource_uri')->nullable()->index());
    }

    public function down(): void
    {
        Schema::table('oauth_access_tokens', function (Blueprint $table): void {
            $table->dropIndex(['resource_uri']);
            $table->dropColumn('resource_uri');
        });
        Schema::table('oauth_auth_codes', fn (Blueprint $table) => $table->dropColumn('resource_uri'));
    }
};
