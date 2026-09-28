<?php

namespace Tests\Unit\Billing;

use App\Services\Billing\InvoiceNumberAllocator;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A new client's invoice-number prefix is never empty.
 *
 * An empty one numbered the invoice `202610-001`, which the allocator cannot
 * read back as a series, so every later invoice started over.
 */
final class InvoiceNumberPrefixTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function names(): iterable
    {
        yield 'plain name' => ['Atlas Imaging', 'ATLA'];
        yield 'short name' => ['Qx', 'QX'];
        yield 'digits count' => ['3D Works', '3DWO'];
        yield 'accents are transliterated, not dropped' => ['Ångström Labs', 'ANGS'];
        yield 'another alphabet is romanised' => ['Ωmega Freight', 'OMEG'];
        yield 'nothing romanisable falls back to the public id' => ['株式会社', '9B4C'];
        yield 'symbols only fall back to the public id' => ['★ — ★', '9B4C'];
    }

    #[DataProvider('names')]
    public function test_the_prefix(string $name, string $expected): void
    {
        $this->assertSame($expected, InvoiceNumberAllocator::prefixFromName($name, '9b4ce1ab-663d-42be-afac-ce7f9ceb6ad7'));
    }

    public function test_a_company_with_neither_refuses_rather_than_numbering_without_a_prefix(): void
    {
        $this->expectException(LogicException::class);

        InvoiceNumberAllocator::prefixFromName('★', '');
    }
}
