<?php

namespace Tests\Feature\Billing;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class OpeningRolloverAuditRemovalTest extends TestCase
{
    public function test_artisan_no_longer_lists_the_spent_opening_rollover_audit(): void
    {
        $this->assertSame(0, Artisan::call('list', ['--raw' => true]));
        $this->assertStringNotContainsString('svc:billing:audit-opening-rollover', Artisan::output());
        $this->assertArrayNotHasKey('svc:billing:audit-opening-rollover', Artisan::all());
    }
}
