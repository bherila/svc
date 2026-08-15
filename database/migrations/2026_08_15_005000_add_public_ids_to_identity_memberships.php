<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'workspace_memberships', 'client_company_memberships'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->uuid('public_id')->nullable()->unique();
            });

            DB::table($tableName)->whereNull('public_id')->orderBy('id')->eachById(
                fn (object $row) => DB::table($tableName)->where('id', $row->id)->update([
                    'public_id' => (string) Str::uuid(),
                ]),
            );

            Schema::table($tableName, function (Blueprint $table): void {
                $table->uuid('public_id')->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        foreach (['client_company_memberships', 'workspace_memberships', 'users'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropUnique($table->getTable().'_public_id_unique');
                $table->dropColumn('public_id');
            });
        }
    }
};
