<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The identity provider's recovery epoch an OAuth credential was captured
     * under, beside the generation already recorded. A provider raises its epoch whenever it
     * is restored from a backup, so everything captured before the restore is refused once
     * the provider reports the new one. Nullable: rows captured before this migration, or
     * from a provider that reports no epoch, have none and are not compared. Adds only absent
     * columns, like the migration that added the generation, so it is safe to run twice.
     * SVC splits the package migration by connection; remembered logins use the next migration.
     */
    public function up(): void
    {
        foreach (['oauth_auth_codes', 'oauth_access_tokens', 'oauth_refresh_tokens'] as $table) {
            $this->addColumn($table, 'provider_epoch', function (Blueprint $blueprint): void {
                $blueprint->unsignedInteger('provider_epoch')->nullable();
            });
        }
    }

    /** Intentionally a no-op, for the same reason as the generation migration. */
    public function down(): void {}

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
