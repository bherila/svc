<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A setting of one MCP credential: an OAuth connection (by client, for one
 * person) or a personal token (by access-token id). It belongs to a person and
 * not to a workspace, like the credential it describes, and it grants nothing:
 * every call is authorized again on its own.
 *
 * @property int $user_id
 * @property string|null $oauth_client_id
 * @property string|null $access_token_id
 * @property bool $show_all_tools
 */
#[Fillable(['user_id', 'oauth_client_id', 'access_token_id', 'show_all_tools'])]
class McpCredentialSetting extends Model
{
    /** Beside the Passport credentials it describes, on whichever connection they use. */
    public function getConnectionName(): ?string
    {
        $configured = config('passport.connection');

        return is_string($configured) && $configured !== '' ? $configured : parent::getConnectionName();
    }

    protected function casts(): array
    {
        return ['show_all_tools' => 'boolean'];
    }
}
