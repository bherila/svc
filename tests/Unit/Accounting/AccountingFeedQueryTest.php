<?php

namespace Tests\Unit\Accounting;

use App\Support\Accounting\AccountingFeed;
use App\Support\Accounting\AccountingFeedQuery;
use App\Support\Accounting\IncomeExportQuery;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AccountingFeedQueryTest extends TestCase
{
    private const string CLIENT = '3b1d2f4a-6c7e-4d8f-9a0b-1c2d3e4f5a6b';

    private const string INVOICE = '4c2e3a5b-7d8f-4e9a-8b1c-2d3e4f5a6b7c';

    public function test_the_defaults_are_a_page_of_fifty_with_no_filter(): void
    {
        $query = AccountingFeedQuery::fromInput(AccountingFeed::InvoiceLines, []);

        $this->assertSame([null, null, null, null, null, 50, null, false], [$query->entryType, $query->clientId, $query->invoiceId, $query->from, $query->to, $query->perPage, $query->cursor, $query->window->requested()]);
        $this->assertSame(50, AccountingFeedQuery::DEFAULT_PER_PAGE);
        $this->assertSame(100, AccountingFeedQuery::MAX_PER_PAGE);
    }

    public function test_every_filter_is_read(): void
    {
        $query = AccountingFeedQuery::fromInput(AccountingFeed::CashIncome, [
            'entry_type' => 'refund', 'client_id' => self::CLIENT, 'invoice_id' => self::INVOICE, 'from' => '2030-01-01', 'to' => '2030-12-31',
            'per_page' => '100', 'cursor' => 'opaque',
        ]);

        $this->assertSame(['refund', self::CLIENT, self::INVOICE, '2030-01-01', '2030-12-31', 100, 'opaque'], [$query->entryType, $query->clientId, $query->invoiceId, $query->from, $query->to, $query->perPage, $query->cursor]);
        $this->assertTrue($query->wants('refund'));
        $this->assertFalse($query->wants('payment'));
        $this->assertTrue(AccountingFeedQuery::fromInput(AccountingFeed::CashIncome, [])->wants('payment'));
    }

    public function test_the_query_key_names_the_feed_and_every_filter(): void
    {
        $key = AccountingFeedQuery::fromInput(AccountingFeed::InvoiceLines, ['entry_type' => 'void', 'client_id' => self::CLIENT, 'from' => '2030-01-01'])->key();

        $this->assertSame('["invoice_lines","void","'.self::CLIENT.'",null,"2030-01-01",null]', $key);
        $this->assertNotSame($key, AccountingFeedQuery::fromInput(AccountingFeed::CashIncome, ['client_id' => self::CLIENT, 'from' => '2030-01-01'])->key());
    }

    /** @return iterable<string, array{AccountingFeed, array<string, mixed>, string}> */
    public static function refusals(): iterable
    {
        yield 'unknown filter' => [AccountingFeed::InvoiceLines, ['page' => '2'], 'page'];
        yield 'a status' => [AccountingFeed::CashIncome, ['status' => 'any'], 'status'];
        yield 'a line type for the cash feed' => [AccountingFeed::CashIncome, ['entry_type' => 'void'], 'entry_type'];
        yield 'a cash type for the line feed' => [AccountingFeed::InvoiceLines, ['entry_type' => 'payment'], 'entry_type'];
        yield 'client not a uuid' => [AccountingFeed::InvoiceLines, ['client_id' => 'abc'], 'client_id'];
        yield 'invoice not a uuid' => [AccountingFeed::InvoiceLines, ['invoice_id' => 'abc'], 'invoice_id'];
        yield 'a malformed date' => [AccountingFeed::InvoiceLines, ['from' => '01/02/2030'], 'from'];
        yield 'to before from' => [AccountingFeed::InvoiceLines, ['from' => '2030-02-01', 'to' => '2030-01-31'], 'to'];
        yield 'zero a page' => [AccountingFeed::InvoiceLines, ['per_page' => '0'], 'per_page'];
        yield 'too many a page' => [AccountingFeed::InvoiceLines, ['per_page' => '101'], 'per_page'];
        yield 'a cursor too long' => [AccountingFeed::InvoiceLines, ['cursor' => str_repeat('a', 2049)], 'cursor'];
        yield 'a date filter on the cash feed beside updated_since' => [AccountingFeed::CashIncome, ['from' => '2030-01-01', 'updated_since' => '2030-01-01T00:00:00Z'], 'from'];
        yield 'a date filter on the cash feed beside a watermark' => [AccountingFeed::CashIncome, ['to' => '2030-01-01', 'watermark' => '2020-01-01T00:00:00Z'], 'to'];
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('refusals')]
    public function test_an_invalid_query_is_refused_naming_the_parameter(AccountingFeed $feed, array $input, string $field): void
    {
        try {
            AccountingFeedQuery::fromInput($feed, $input);
            $this->fail('The query was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    public function test_the_boundary_of_a_page_and_of_a_date_range_is_accepted(): void
    {
        $query = AccountingFeedQuery::fromInput(AccountingFeed::InvoiceLines, ['per_page' => '1', 'from' => '2030-01-31', 'to' => '2030-01-31']);

        $this->assertSame([1, '2030-01-31', '2030-01-31'], [$query->perPage, $query->from, $query->to]);
        $this->assertSame(100, AccountingFeedQuery::fromInput(AccountingFeed::InvoiceLines, ['per_page' => '100'])->perPage);
    }

    public function test_line_entries_never_change_so_every_filter_may_follow_updated_since(): void
    {
        $query = AccountingFeedQuery::fromInput(AccountingFeed::InvoiceLines, [
            'entry_type' => 'correction', 'client_id' => self::CLIENT, 'invoice_id' => self::INVOICE, 'from' => '2030-01-01', 'to' => '2030-02-01', 'updated_since' => '2030-01-01T00:00:00Z',
        ]);

        $this->assertTrue($query->window->requested());
        $this->assertSame('2030-01-01 00:00:00', $query->window->since());
    }

    public function test_cash_filters_on_what_never_changes_may_follow_updated_since(): void
    {
        $query = AccountingFeedQuery::fromInput(AccountingFeed::CashIncome, ['entry_type' => 'payment', 'client_id' => self::CLIENT, 'invoice_id' => self::INVOICE, 'updated_since' => '2030-01-01T00:00:00Z']);

        $this->assertSame('2030-01-01 00:00:00', $query->window->since());
        // A date filter is fine for a period pass, which names no change window.
        $this->assertSame('2030-01-01', AccountingFeedQuery::fromInput(AccountingFeed::CashIncome, ['from' => '2030-01-01', 'cursor' => 'x'])->from);
    }

    public function test_the_feeds_name_their_entry_types_and_whether_dates_can_change(): void
    {
        $this->assertSame(['invoice_line', 'correction', 'void'], AccountingFeed::InvoiceLines->entryTypes());
        $this->assertContains('refund_reversal', AccountingFeed::CashIncome->entryTypes());
        $this->assertFalse(AccountingFeed::InvoiceLines->datesCanChange());
        $this->assertTrue(AccountingFeed::CashIncome->datesCanChange());
    }

    public function test_the_export_year_is_one_required_integer_in_range(): void
    {
        $this->assertSame(2030, IncomeExportQuery::year(['year' => '2030']));
        $this->assertSame(2000, IncomeExportQuery::year(['year' => '2000']));
        $this->assertSame(2100, IncomeExportQuery::year(['year' => '2100']));
        foreach ([[], ['year' => '1999'], ['year' => '2101'], ['year' => 'abc'], ['year' => '2030.5'], ['year' => '2030', 'month' => '1']] as $input) {
            try {
                IncomeExportQuery::year($input);
                $this->fail('The query was accepted: '.json_encode($input));
            } catch (ValidationException $e) {
                $this->assertNotSame([], $e->errors());
            }
        }
    }
}
