<?php

namespace Tests\Unit\Payments;

use App\Models\WorkspacePaymentConnection;
use Carbon\CarbonInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(WorkspacePaymentConnection::class)]
final class WorkspacePaymentConnectionCastsTest extends TestCase
{
    public function test_the_key_and_the_signing_secret_are_encrypted_at_rest_and_read_back(): void
    {
        $row = new WorkspacePaymentConnection;
        $row->forceFill(['secret' => 'rk_live_synthetic_casts_a1b2', 'webhook_secret' => 'whsec_synthetic_casts']);
        $raw = $row->getAttributes();

        $this->assertSame('encrypted', $row->getCasts()['secret'] ?? null);
        $this->assertSame('encrypted', $row->getCasts()['webhook_secret'] ?? null);
        $this->assertNotSame('rk_live_synthetic_casts_a1b2', $raw['secret']);
        $this->assertNotSame('whsec_synthetic_casts', $raw['webhook_secret']);
        $this->assertSame('rk_live_synthetic_casts_a1b2', $row->secret());
        $this->assertSame('whsec_synthetic_casts', $row->webhookSecret());
    }

    public function test_the_webhook_check_is_an_array_and_an_instant(): void
    {
        $row = new WorkspacePaymentConnection;
        $row->forceFill(['webhook_check_missing' => ['a.one'], 'webhook_checked_at' => '2026-11-07 10:00:00']);

        $this->assertSame('array', $row->getCasts()['webhook_check_missing'] ?? null);
        $this->assertSame(['a.one'], $row->webhook_check_missing);
        $this->assertInstanceOf(CarbonInterface::class, $row->webhook_checked_at);
        $this->assertSame('2026-11-07 10:00:00', $row->webhook_checked_at->format('Y-m-d H:i:s'));
    }
}
