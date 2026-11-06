<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-credential MCP settings, kept in SVC's own table because the
        // credential tables belong to the auth and Passport packages. A row
        // names exactly one credential:
        //   - an OAuth connection (a connected app), by its client, for this
        //     person: a refresh issues a new access token but not a new
        //     connection, so the setting survives it;
        //   - a personal token, by its access-token id.
        // The table lives on Passport's connection, beside the credentials it
        // describes, so both foreign keys cascade and the setting goes with its
        // credential. The person is an indexed id like the one on
        // oauth_access_tokens: the users table may be on another connection.
        // Schema only: no rows are written here.
        Schema::connection($this->getConnection())->create('mcp_credential_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index('mcp_credential_settings_user_index');
            $table->foreignUuid('oauth_client_id')->nullable()->constrained('oauth_clients')->cascadeOnDelete();
            $table->char('access_token_id', 80)->nullable();
            // Whether `initialize` lists every authorized tool, as the
            // `X-SVC-Toolsets: all` header does (docs/mcp.md).
            $table->boolean('show_all_tools')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'oauth_client_id'], 'mcp_credential_settings_connection_unique');
            $table->unique('access_token_id', 'mcp_credential_settings_token_unique');
            $table->foreign('access_token_id', 'mcp_credential_settings_token_foreign')
                ->references('id')->on('oauth_access_tokens')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('mcp_credential_settings');
    }

    /** Passport's connection, as its own migrations and models use. */
    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
