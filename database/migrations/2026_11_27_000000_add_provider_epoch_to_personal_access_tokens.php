<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('personal_access_tokens') || Schema::hasColumn('personal_access_tokens', 'provider_epoch')) {
            return;
        }

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->unsignedInteger('provider_epoch')->nullable();
        });
    }

    /** Preserve captured epochs when rolling back code, as the package's OAuth migration does. */
    public function down(): void {}
};
