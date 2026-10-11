<?php

namespace Tests\Unit\Access;

use App\Support\Access\PortalAccessScope;
use App\Support\Access\PortalAccessState;
use App\Support\Access\PortalAccessVersion;
use JsonException;
use PHPUnit\Framework\TestCase;

final class PortalAccessVersionTest extends TestCase
{
    public function test_a_version_covers_identity_role_scope_project_set_and_installation_key(): void
    {
        $state = new PortalAccessState(PortalAccessScope::Projects, ['project-alpha', 'project-beta']);
        $version = PortalAccessVersion::for('membership-one', 'client', $state, 'synthetic-key');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $version);
        $this->assertSame($version, PortalAccessVersion::for('membership-one', 'client', new PortalAccessState(PortalAccessScope::Projects, ['project-beta', 'project-alpha']), 'synthetic-key'));
        $this->assertNotSame($version, PortalAccessVersion::for('membership-two', 'client', $state, 'synthetic-key'));
        $this->assertNotSame($version, PortalAccessVersion::for('membership-one', 'other-role', $state, 'synthetic-key'));
        $this->assertNotSame($version, PortalAccessVersion::for('membership-one', 'client', new PortalAccessState(PortalAccessScope::Company, []), 'synthetic-key'));
        $this->assertNotSame($version, PortalAccessVersion::for('membership-one', 'client', new PortalAccessState(PortalAccessScope::Projects, ['project-alpha']), 'synthetic-key'));
        $this->assertNotSame($version, PortalAccessVersion::for('membership-one', 'client', $state, 'other-synthetic-key'));
    }

    public function test_a_value_that_cannot_be_serialized_is_refused_instead_of_minting_a_version(): void
    {
        $this->expectException(JsonException::class);
        PortalAccessVersion::for('membership-one', "\xFF", new PortalAccessState(PortalAccessScope::Company, []), 'synthetic-key');
    }
}
