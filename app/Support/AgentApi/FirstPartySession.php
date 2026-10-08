<?php

namespace App\Support\AgentApi;

use Illuminate\Http\Request;

/**
 * The signed-in website calling `/api/v1` with its own session.
 *
 * The web UI, OAuth REST clients and MCP share one API (#387). A browser
 * request carries no bearer token: `AuthenticateFirstPartySession` admits it on
 * the session cookie plus the framework's request-forgery check, and gives it
 * a synthetic access token under {@see self::CLIENT_ID} holding
 * {@see self::scopes()}. Every role and policy check downstream is unchanged;
 * only the OAuth-specific gates differ:
 *
 * - scopes: a session is the user themself, so it holds every API scope except
 *   `mcp:use`, which is the MCP transport's and not an action;
 * - the agent write cutovers (`AGENT_API_*_WRITES_ENABLED`) gate OAuth
 *   principals only - they exist to withhold agent access, and switching one off
 *   must not break the website (see {@see AgentWriteCutover}).
 *
 * The marker is a request attribute set only by that middleware, so a client
 * cannot claim it, and an MCP call's internal sub-request never carries it.
 */
final class FirstPartySession
{
    public const string CLIENT_ID = 'svc-web';

    public const string ATTRIBUTE = 'svc_first_party_session';

    /** @return list<string> */
    public static function scopes(): array
    {
        return array_values(array_filter(
            array_keys(AgentApiScopes::descriptions()),
            static fn (string $scope): bool => $scope !== AgentApiScopes::MCP_USE,
        ));
    }

    public static function is(?Request $request): bool
    {
        return $request?->attributes->get(self::ATTRIBUTE) === true;
    }

    public static function current(): bool
    {
        return app()->bound('request') && self::is(app('request'));
    }
}
