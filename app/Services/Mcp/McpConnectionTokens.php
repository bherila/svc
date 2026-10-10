<?php

namespace App\Services\Mcp;

use App\Models\AgentPrincipal;
use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use Laravel\Passport\Passport;

/**
 * Personal access tokens bound to the MCP resource, for the MCP smokes.
 *
 * The MCP endpoint is its own protected resource (APP_URL/api/v1/mcp) and
 * accepts only tokens bound to it. A personal token is bound to whatever the
 * current request names, and a request that names nothing is taken as the REST
 * resource, so issuance names the MCP resource both ways the access-token
 * repository reads it - the validated attribute and the `resource` parameter -
 * before Passport signs the JWT. Never patch `resource_uri` afterwards: the
 * signed audience would still name the other resource.
 */
final class McpConnectionTokens
{
    /** @param list<string> $scopes */
    public function issue(AgentPrincipal $principal, string $clientId, string $name, array $scopes): string
    {
        $resource = OAuthResourceIndicator::resource('mcp');
        $request = request();
        $hadResource = $request->query->has('resource');
        $previousResource = $request->query->get('resource');
        $request->attributes->set(OAuthResourceIndicator::REQUEST_ATTRIBUTE, $resource);
        $request->query->set('resource', $resource);
        try {
            $issued = $principal->createToken($name, $scopes);
        } finally {
            $request->attributes->remove(OAuthResourceIndicator::REQUEST_ATTRIBUTE);
            $hadResource ? $request->query->set('resource', $previousResource) : $request->query->remove('resource');
        }

        // The repository binds the row and the signed JWT during issuance.
        // Checking the selected client and resource here keeps a misconfigured
        // Passport client from producing a credential the caller cannot revoke
        // or the MCP endpoint would refuse.
        Passport::token()->newQuery()
            ->whereKey($issued->accessTokenId)
            ->where('client_id', $clientId)
            ->where('resource_uri', $resource)
            ->firstOrFail();

        return $issued->accessToken;
    }
}
