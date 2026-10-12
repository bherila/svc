<?php

namespace Tests\Unit\Square;

use App\Support\Square\SquareHostedPage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SquareHostedPageTest extends TestCase
{
    public function test_only_absolute_https_square_urls_are_returned_unchanged(): void
    {
        foreach (['https://squareup.com/pay-invoice/inv_fixture_1', 'https://squareupsandbox.com/pay-invoice/inv_fixture_1', 'https://checkout.squareup.com/inv_fixture_1'] as $url) {
            $this->assertSame($url, SquareHostedPage::url($url));
        }
    }

    #[DataProvider('untrustedUrls')]
    public function test_untrusted_urls_are_dropped(mixed $url): void
    {
        $this->assertNull(SquareHostedPage::url($url));
    }

    public static function untrustedUrls(): array
    {
        return array_map(static fn (mixed $url): array => [$url], [
            null, [], '', '/pay-invoice/inv_fixture_1', 'http://squareup.com/inv_fixture_1',
            'https://squareup.com.example.test/inv_fixture_1', 'https://fakesquareup.com/inv_fixture_1',
            'https://squareup.com@evil.example.test/inv_fixture_1', 'https://fixture@'.'squareup.com/inv_fixture_1',
            'https://squareup.com:443/inv_fixture_1', 'javascript:alert(1)',
            'https://squareup.com/'.str_repeat('x', 2048),
        ]);
    }
}
