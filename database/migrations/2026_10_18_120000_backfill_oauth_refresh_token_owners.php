<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Record the owner on refresh tokens issued before refresh tokens recorded
     * one, while their access-token rows still exist (#456). Revoking a person's
     * connections finds a refresh token by this owner once Passport purges its
     * expired access token; without it such a token belongs to no one the
     * revocation can name. Never overwrites an owner, and never guesses an
     * orphan's: those are reported by `svc:auth:revoke-connections`.
     */
    public function up(): void
    {
        $connection = DB::connection($this->getConnection());
        $schema = $connection->getSchemaBuilder();
        if (! $schema->hasTable('oauth_refresh_tokens')
            || ! $schema->hasTable('oauth_access_tokens')
            || ! $schema->hasColumn('oauth_refresh_tokens', 'provider_user_id')) {
            return;
        }

        $connection->table('oauth_refresh_tokens')->whereNull('provider_user_id')
            ->whereIn('access_token_id', $connection->table('oauth_access_tokens')->select('id')->whereNotNull('user_id'))
            ->update(['provider_user_id' => $connection->table('oauth_access_tokens')->select('user_id')
                ->whereColumn('oauth_access_tokens.id', 'oauth_refresh_tokens.access_token_id')]);
    }

    /** The recorded owner is correct either way; nothing to undo. */
    public function down(): void {}

    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
