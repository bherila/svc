<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->table('oauth_auth_codes', fn (Blueprint $table) => $table->string('resource_uri')->nullable());
        $schema->table('oauth_access_tokens', fn (Blueprint $table) => $table->string('resource_uri')->nullable()->index());
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->table('oauth_access_tokens', function (Blueprint $table): void {
            $table->dropIndex(['resource_uri']);
            $table->dropColumn('resource_uri');
        });
        $schema->table('oauth_auth_codes', fn (Blueprint $table) => $table->dropColumn('resource_uri'));
    }

    /**
     * Use the same connection as Passport's models and original migrations.
     */
    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
