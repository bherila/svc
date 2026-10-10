<?php

namespace App\Services\ApiCredentials;

use App\Support\AgentApi\AgentApiScopes;
use BWH\Auth\OAuth\Credentials\GrantableScopes;

/**
 * The permissions a REST credential may carry: those some REST operation in
 * the OpenAPI document actually requires, without the MCP transport.
 *
 * Read from the contract rather than the scope catalog, because a scope whose
 * operations exist only as MCP tools would give a credential that cannot call
 * anything. As each REST operation is added, its scope becomes grantable with
 * it. Bound for the auth package's credential service (#384, #408).
 */
final class OpenApiGrantableScopes implements GrantableScopes
{
    /** @var array<string, string>|null */
    private static ?array $scopes = null;

    public function scopes(): array
    {
        if (self::$scopes === null) {
            $document = json_decode((string) file_get_contents(public_path('openapi/svc-agent-v1.json')), true, flags: JSON_THROW_ON_ERROR);
            $used = [];
            foreach ($document['paths'] as $path) {
                foreach ($path as $operation) {
                    foreach ($operation['security'][0]['oauth2'] ?? [] as $scope) {
                        $used[$scope] = true;
                    }
                }
            }
            self::$scopes = array_filter(
                AgentApiScopes::descriptions(),
                static fn (string $scope): bool => $scope !== AgentApiScopes::MCP_USE && isset($used[$scope]),
                ARRAY_FILTER_USE_KEY,
            );
        }

        return self::$scopes;
    }
}
