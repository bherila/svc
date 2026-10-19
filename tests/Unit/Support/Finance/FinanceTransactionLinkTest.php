<?php

namespace Tests\Unit\Support\Finance;

use App\Support\Finance\FinanceTransactionLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The link back to a finance transaction comes from a configured template and
 * the transaction's UUID, and from nothing else: anything that would not be an
 * absolute http(s) address to that transaction is no link at all.
 */
final class FinanceTransactionLinkTest extends TestCase
{
    private const string UUID = '0b7c6f2e-3d4a-4f5b-8c9d-0e1f2a3b4c5d';

    public function test_the_placeholder_is_replaced_by_the_transaction_uuid(): void
    {
        $link = new FinanceTransactionLink('https://finance.example.test/transactions/{transaction_uuid}');

        $this->assertSame('https://finance.example.test/transactions/'.self::UUID, $link->href(self::UUID));
    }

    public function test_the_uuid_may_sit_anywhere_in_the_template_and_is_lowercased(): void
    {
        $link = new FinanceTransactionLink('http://localhost:8080/ledger?open={transaction_uuid}&tab=links');

        $this->assertSame(
            'http://localhost:8080/ledger?open='.self::UUID.'&tab=links',
            $link->href(strtoupper(self::UUID)),
        );
    }

    public function test_the_scheme_is_matched_without_regard_to_case(): void
    {
        $link = new FinanceTransactionLink('HTTPS://finance.example.test/t/{transaction_uuid}');

        $this->assertSame('HTTPS://finance.example.test/t/'.self::UUID, $link->href(self::UUID));
    }

    public function test_surrounding_whitespace_in_the_configured_template_is_ignored(): void
    {
        $link = new FinanceTransactionLink("  https://finance.example.test/t/{transaction_uuid}\n");

        $this->assertSame('https://finance.example.test/t/'.self::UUID, $link->href(self::UUID));
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function unusableTemplates(): array
    {
        return [
            'unset' => [null],
            'empty' => [''],
            'blank' => ['   '],
            'no placeholder' => ['https://finance.example.test/transactions/'],
            'relative' => ['/transactions/{transaction_uuid}'],
            'scheme-relative' => ['//finance.example.test/{transaction_uuid}'],
            'script' => ['javascript:alert("{transaction_uuid}")'],
            'data' => ['data:text/html,{transaction_uuid}'],
            'other scheme' => ['ftp://finance.example.test/{transaction_uuid}'],
            'no host' => ['https:///{transaction_uuid}'],
            'scheme without authority' => ['https:{transaction_uuid}'],
        ];
    }

    #[DataProvider('unusableTemplates')]
    public function test_a_template_that_is_not_an_absolute_http_address_with_the_placeholder_gives_no_link(?string $template): void
    {
        $this->assertNull((new FinanceTransactionLink($template))->href(self::UUID));
    }

    public function test_a_value_that_is_not_a_uuid_gives_no_link(): void
    {
        $link = new FinanceTransactionLink('https://finance.example.test/transactions/{transaction_uuid}');

        $this->assertNull($link->href('not-a-uuid'));
        $this->assertNull($link->href('../../admin'));
        $this->assertNull($link->href(''));
    }
}
