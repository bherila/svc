<?php

namespace Tests\Unit;

use App\Support\AgentApi\AgentApiScopes;
use PHPUnit\Framework\TestCase;

final class AgentScopeRegistrationTest extends TestCase
{
    public function test_every_documented_oauth_scope_is_registered_for_consent(): void
    {
        // AppServiceProvider passes this map to Passport::tokensCan(). Losing
        // an item makes a documented grant unavailable during authorization.
        $document = json_decode(file_get_contents(__DIR__.'/../../public/openapi/svc-agent-v1.json'), true, flags: JSON_THROW_ON_ERROR);
        $documented = array_keys($document['components']['securitySchemes']['oauth2']['flows']['authorizationCode']['scopes']);
        $registered = array_keys(AgentApiScopes::descriptions());
        sort($documented);
        sort($registered);
        $this->assertSame($documented, $registered);
        foreach (AgentApiScopes::descriptions() as $description) {
            $this->assertNotSame('', trim($description));
        }
    }
}
