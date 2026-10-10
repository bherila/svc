<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * From bherila/auth-laravel v0.23 (same file name), on Passport's connection like
 * the OAuth server metadata migration.
 */
return new class extends Migration
{
    /**
     * The provider subject and credential generation an authorization code, access token
     * or refresh token was issued under. A refresh token also records its owner, so it
     * stays checkable after its expired access token is purged. Nullable: rows
     * issued before this migration have none, and are retired rather than upgraded once
     * provider identity enforcement is enabled. Adds only absent columns, like the
     * OAuth server metadata migration, so it is safe beside application-owned columns.
     */
    public function up(): void
    {
        foreach (['oauth_auth_codes', 'oauth_access_tokens', 'oauth_refresh_tokens'] as $table) {
            $this->addColumn($table, 'provider_subject', function (Blueprint $blueprint): void {
                $blueprint->string('provider_subject', 191)->nullable();
            });
            $this->addColumn($table, 'provider_generation', function (Blueprint $blueprint): void {
                $blueprint->unsignedBigInteger('provider_generation')->nullable();
            });
        }
        $this->addColumn('oauth_refresh_tokens', 'provider_user_id', function (Blueprint $blueprint): void {
            // Indexed: revoking an account's credentials looks refresh tokens up by owner.
            $blueprint->string('provider_user_id', 191)->nullable()->index();
        });
    }

    /** Intentionally a no-op, for the same reason as the OAuth server metadata migration. */
    public function down(): void {}

    /**
     * Use the same connection as Passport's models and original migrations.
     */
    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }

    /** @param Closure(Blueprint): void $definition */
    private function addColumn(string $tableName, string $column, Closure $definition): void
    {
        $schema = Schema::connection($this->getConnection());
        if (! $schema->hasTable($tableName) || $schema->hasColumn($tableName, $column)) {
            return;
        }

        $schema->table($tableName, $definition);
    }
};
