<?php

namespace Tests\Unit;

use App\Services\Access\DelegatedStaffAccess;
use BWH\Auth\OAuth\DelegatedAccess\ApplicationAccessAdapter;
use Tests\TestCase;

final class DelegatedStaffAccessBindingTest extends TestCase
{
    public function test_the_identity_providers_staff_endpoint_is_answered_by_the_staff_adapter(): void
    {
        // The package registers its delegated access route only while an adapter is bound.
        $this->assertInstanceOf(DelegatedStaffAccess::class, $this->app->make(ApplicationAccessAdapter::class));
        $this->assertTrue($this->app['router']->has('bherila-auth.delegated-access'));
    }
}
