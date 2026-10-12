<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_maintenance_locks', function (Blueprint $table): void {
            $table->foreignId('workspace_id')->primary()->constrained('workspaces')->cascadeOnDelete();
        });
        DB::table('billing_maintenance_locks')->insertUsing(['workspace_id'], DB::table('workspaces')->select('id'));
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_maintenance_locks');
    }
};
