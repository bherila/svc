<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Validation permits 20,000 Unicode characters, or up to 80,000 UTF-8
        // bytes. MariaDB TEXT holds only 65,535 bytes.
        foreach (['client_companies', 'client_contacts'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->mediumText('notes')->nullable()->change());
        }
    }

    public function down(): void
    {
        $length = DB::getDriverName() === 'sqlite' ? 'LENGTH(CAST(notes AS BLOB))' : 'OCTET_LENGTH(notes)';
        foreach (['client_companies', 'client_contacts'] as $table) {
            if (DB::table($table)->whereRaw($length.' > 65535')->exists()) {
                throw new RuntimeException('Cannot narrow client notes while values exceed the TEXT byte limit.');
            }
        }
        foreach (['client_companies', 'client_contacts'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->text('notes')->nullable()->change());
        }
    }
};
