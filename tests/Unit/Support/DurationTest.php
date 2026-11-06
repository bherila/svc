<?php

namespace Tests\Unit\Support;

use App\Support\Time\Duration;
use App\Support\Time\DurationStyle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The PHP half of the shared table; the TypeScript half is `duration.test.ts`. */
class DurationTest extends TestCase
{
    /** @return array{parse: list<array{input: string, minutes: int|null}>, format: list<array{minutes: int, style: string, text: string}>} */
    private static function cases(): array
    {
        /** @var array{parse: list<array{input: string, minutes: int|null}>, format: list<array{minutes: int, style: string, text: string}>} */
        return json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/duration-cases.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string, array{string, int|null}> */
    public static function parseCases(): iterable
    {
        foreach (self::cases()['parse'] as $case) {
            yield sprintf('"%s"', $case['input']) => [$case['input'], $case['minutes']];
        }
    }

    /** @return iterable<string, array{int, string, string}> */
    public static function formatCases(): iterable
    {
        foreach (self::cases()['format'] as $case) {
            yield sprintf('%d min as %s', $case['minutes'], $case['style']) => [$case['minutes'], $case['style'], $case['text']];
        }
    }

    #[DataProvider('parseCases')]
    public function test_parse(string $input, ?int $minutes): void
    {
        $this->assertSame($minutes, Duration::parse($input));
    }

    #[DataProvider('formatCases')]
    public function test_format(int $minutes, string $style, string $text): void
    {
        $this->assertSame($text, Duration::format($minutes, DurationStyle::from($style)));
    }

    public function test_invoices_are_always_decimal(): void
    {
        $this->assertSame('2.5 h', Duration::forInvoice(150));
    }
}
