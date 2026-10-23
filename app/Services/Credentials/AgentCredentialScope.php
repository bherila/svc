<?php

namespace App\Services\Credentials;

/** How wide an {@see AgentCredentialSelection} reaches within its one owner. */
enum AgentCredentialScope: string
{
    /** One access token by id: a connection disconnecting itself. */
    case Token = 'token';

    /** Tokens one issuer stored under an exact name. */
    case Name = 'name';

    /** Every access token, refresh token and authorization code, optionally of one client. */
    case Connections = 'connections';
}
