<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Remembered logins live on the application connection, independently of Passport. */
    public function up(): void
    {
        if (! Schema::hasTable('provider_remembered_logins') || Schema::hasColumn('provider_remembered_logins', 'epoch')) {
            return;
        }

        Schema::table('provider_remembered_logins', function (Blueprint $table): void {
            $table->unsignedInteger('epoch')->nullable();
        });
    }

    /** Preserve captured baselines across code rollback, as the package migration does. */
    public function down(): void {}
};
