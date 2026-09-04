<?php

namespace Tests\Unit\Billing;

use App\Casts\EmailAddressList;
use App\Models\ClientInvoiceEmailDelivery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The one thing every reader of an address column is allowed to assume.
 *
 * A `json` column is storage, not a type. Before this cast the invoice screen
 * called `.join` on whatever `json_decode` handed back, so a row holding a bare
 * string - the shape a mail log from another system arrives in - was a
 * TypeError that blanked the entire page, taking the well-formed deliveries
 * above and below it with it. Every case below is a shape that did that.
 */
final class EmailAddressListCastTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, list<string>}>
     */
    public static function storedValues(): iterable
    {
        yield 'a proper list is left alone' => [
            '["ap@synthetic.test","ar@synthetic.test"]',
            ['ap@synthetic.test', 'ar@synthetic.test'],
        ];
        // The shape that took production down: one address, stored bare.
        yield 'a bare string becomes a list of one' => ['"ap@synthetic.test"', ['ap@synthetic.test']];
        // Gapped keys are why this is not just a cast to array. PHP keeps the
        // keys, `json_encode` writes `{"0":...,"2":...}`, and an object has no
        // `.join`.
        yield 'a gapped array is renumbered into a list' => [
            '{"0":"ap@synthetic.test","2":"ar@synthetic.test"}',
            ['ap@synthetic.test', 'ar@synthetic.test'],
        ];
        yield 'a null column reads as no addresses, never null' => [null, []];
        yield 'an empty list stays empty' => ['[]', []];
        yield 'a number is not an address' => ['42', []];
        // A `json` column in MySQL cannot hold this - the engine rejects it -
        // but SQLite will, and text that is not JSON is corrupt past the point
        // where guessing an address out of it would be honest.
        yield 'an unparseable column reads as no addresses' => ['not json at all', []];
        yield 'addresses are trimmed' => ['["  ap@synthetic.test\n"]', ['ap@synthetic.test']];
        yield 'blank entries are dropped rather than rendered as gaps' => [
            '["ap@synthetic.test","","   "]',
            ['ap@synthetic.test'],
        ];
        yield 'a repeated address is listed once' => [
            '["ap@synthetic.test","ap@synthetic.test"]',
            ['ap@synthetic.test'],
        ];
        // Nested structure cannot be rendered as an address, and letting one
        // through would put `[object Object]` on an invoice record.
        yield 'a nested value is dropped, not stringified' => [
            '["ap@synthetic.test",{"email":"ar@synthetic.test"}]',
            ['ap@synthetic.test'],
        ];
        // The same value first rather than last. Dropped means skipped, not
        // stopped: giving up at the first unreadable entry would silently lose
        // every real address behind it, and the record would look complete.
        yield 'a nested value does not hide the addresses after it' => [
            '[{"email":"ar@synthetic.test"},"ap@synthetic.test"]',
            ['ap@synthetic.test'],
        ];
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('storedValues')]
    public function test_whatever_the_column_holds_reads_back_as_a_list(mixed $stored, array $expected): void
    {
        $read = (new EmailAddressList)->get(new ClientInvoiceEmailDelivery, 'recipients', $stored, []);

        $this->assertSame($expected, $read);
        // Asserted separately because this is the property the browser depends
        // on: `json_encode` writes a PHP list as an array and anything else as
        // an object, and only one of those has `.join`.
        $this->assertStringStartsWith('[', (string) json_encode($read));
    }

    public function test_a_malformed_value_is_normalised_on_the_way_in_as_well(): void
    {
        // A writer that has not been through `InvoiceEmailDraft` - an importer,
        // a console command, a future caller - cannot leave a row that only the
        // reader is holding together.
        $written = (new EmailAddressList)->set(new ClientInvoiceEmailDelivery, 'recipients', [
            2 => '  ap@synthetic.test ',
            5 => '',
        ], []);

        $this->assertSame(['recipients' => '["ap@synthetic.test"]'], $written);
    }
}
