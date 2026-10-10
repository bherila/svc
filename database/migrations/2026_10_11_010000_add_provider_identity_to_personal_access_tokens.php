<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The provider subject and credential generation a Sanctum personal access
     * token was issued under, as the OAuth credentials record theirs. Nullable:
     * tokens issued before this have none and are refused for a provider-bound
     * account once provider identity enforcement is enabled.
     */
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->string('provider_subject', 191)->nullable();
            $table->unsignedBigInteger('provider_generation')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropColumn(['provider_subject', 'provider_generation']);
        });
    }
};
