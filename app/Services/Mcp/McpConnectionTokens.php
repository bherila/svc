<?php

namespace App\Services\Mcp;

use App\Models\AgentPrincipal;
use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use BWH\Auth\OAuth\Server\ProviderIdentityTokens;
use Laravel\Passport\Passport;

/**
 * Personal access tokens bound to one protected resource: the MCP resource for
 * the MCP smokes, or either resource for an operator-issued agent token.
 *
 * The MCP endpoint is its own protected resource (APP_URL/api/v1/mcp) and
 * accepts only tokens bound to it. A personal token is bound to whatever the
 * current request names, and a request that names nothing is taken as the REST
 * resource, so issuance names the MCP resource both ways the access-token
 * repository reads it - the validated attribute and the `resource` parameter -
 * before Passport signs the JWT. Never patch `resource_uri` afterwards: the
 * signed audience would still name the other resource.
 *
 * A console issue has no browser session to take a provider stamp from, so a
 * caller that has checked the person's provider identity passes the stamp in
 * and it is carried to the access-token repository the same way a code or
 * refresh token carries its own. Without one, the repository records the
 * unbound stamp for an unbound person and nothing for a bound one.
 */
final class McpConnectionTokens
{
    /**
     * @param  list<string>  $scopes
     * @param  'mcp'|'rest'  $resourceName
     * @param  array{subject: string, generation: int}|null  $providerStamp
     * @return string the signed access token
     */
    public function issue(
        AgentPrincipal $principal,
        string $clientId,
        string $name,
        array $scopes,
        string $resourceName = 'mcp',
        ?array $providerStamp = null,
    ): string {
        return $this->issueToken($principal, $clientId, $name, $scopes, $resourceName, $providerStamp)['token'];
    }

    /**
     * {@see issue()}, returning the persisted row's id alongside the token.
     *
     * @param  list<string>  $scopes
     * @param  'mcp'|'rest'  $resourceName
     * @param  array{subject: string, generation: int}|null  $providerStamp
     * @return array{id: string, token: string}
     */
    public function issueToken(
        AgentPrincipal $principal,
        string $clientId,
        string $name,
        array $scopes,
        string $resourceName = 'mcp',
        ?array $providerStamp = null,
    ): array {
        $resource = OAuthResourceIndicator::resource($resourceName);
        $request = request();
        $hadResource = $request->query->has('resource');
        $previousResource = $request->query->get('resource');
        $request->attributes->set(OAuthResourceIndicator::REQUEST_ATTRIBUTE, $resource);
        $request->query->set('resource', $resource);
        $hadStamp = $request->attributes->has(ProviderIdentityTokens::REQUEST_ATTRIBUTE);
        $previousStamp = $request->attributes->get(ProviderIdentityTokens::REQUEST_ATTRIBUTE);
        if ($providerStamp !== null) {
            $request->attributes->set(ProviderIdentityTokens::REQUEST_ATTRIBUTE, $providerStamp);
        }
        try {
            $issued = $principal->createToken($name, $scopes);
        } finally {
            $request->attributes->remove(OAuthResourceIndicator::REQUEST_ATTRIBUTE);
            $hadStamp
                ? $request->attributes->set(ProviderIdentityTokens::REQUEST_ATTRIBUTE, $previousStamp)
                : $request->attributes->remove(ProviderIdentityTokens::REQUEST_ATTRIBUTE);
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

        return ['id' => (string) $issued->accessTokenId, 'token' => (string) $issued->accessToken];
    }
}
