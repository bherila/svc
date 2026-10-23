<?php

namespace Tests\Unit\Square;

use App\Support\Square\SquareDashboardPage;
use PHPUnit\Framework\TestCase;

final class SquareDashboardPageTest extends TestCase
{
    public function test_dashboard_navigation_is_finished_on_the_server_and_uses_opaque_ids(): void
    {
        $this->assertSame('https://squareup.com/dashboard/invoices/inv%3A0-fixture1', SquareDashboardPage::invoice('production', 'inv:0-fixture1'));
        $this->assertSame('https://squareupsandbox.com/dashboard/invoices/inv_fixture_1', SquareDashboardPage::invoice('sandbox', 'inv_fixture_1'));
        $this->assertSame('https://squareup.com/dashboard/invoices/_SyntheticLegacy%3D%3D', SquareDashboardPage::invoice('production', '_SyntheticLegacy=='));
        $this->assertNull(SquareDashboardPage::invoice('invalid', 'inv_fixture_1'));
        $this->assertNull(SquareDashboardPage::invoice('production', 'invalid/path'));
    }
}
