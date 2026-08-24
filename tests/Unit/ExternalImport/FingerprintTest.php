<?php

namespace Tests\Unit\ExternalImport;

use App\Services\ExternalImport\Fingerprint;
use PHPUnit\Framework\TestCase;

class FingerprintTest extends TestCase
{
    public function test_row_fingerprint_is_order_independent_and_not_plaintext(): void
    {
        $first = Fingerprint::row(['name' => 'Alice Example', 'id' => 7]);
        $second = Fingerprint::row(['id' => 7, 'name' => 'Alice Example']);

        $this->assertSame($first, $second);
        $this->assertSame(64, strlen($first));
        $this->assertStringNotContainsString('Alice Example', $first);
    }
}
