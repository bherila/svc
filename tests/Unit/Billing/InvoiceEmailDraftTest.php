<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\InvoiceEmailDraft;
use DomainException;
use PHPUnit\Framework\TestCase;

/**
 * What is about to be sent with an invoice, once it has been checked.
 *
 * This was written with no test of its own and exercised only through the HTTP
 * endpoint, which asserts what came out the far end rather than what this
 * decides. Every piece of normalisation here - the trimming, the
 * de-duplication, the blank removal, the blind copies minus the recipients -
 * could have been removed without a single test noticing, and each of them is
 * the difference between an address the mail server accepts and one it refuses.
 *
 * No database: this is a value object, and the point of it being one is that
 * its rules can be read and asserted without standing up an invoice.
 */
class InvoiceEmailDraftTest extends TestCase
{
    public function test_addresses_are_trimmed(): void
    {
        // A recipient pasted out of a spreadsheet arrives with whitespace, and
        // an untrimmed address is one the mail server rejects.
        $draft = InvoiceEmailDraft::of(["  ap@synthetic.test\t"], [], 'Invoice', null);

        $this->assertSame(['ap@synthetic.test'], $draft->recipients);
    }

    public function test_a_repeated_recipient_is_sent_to_once(): void
    {
        $draft = InvoiceEmailDraft::of(
            ['ap@synthetic.test', 'ap@synthetic.test ', 'second@synthetic.test'],
            [],
            'Invoice',
            null,
        );

        // Twice on the To line is a duplicate the recipient cannot explain, and
        // after trimming these two are the same address.
        $this->assertSame(['ap@synthetic.test', 'second@synthetic.test'], $draft->recipients);
    }

    public function test_blank_entries_are_dropped_rather_than_sent(): void
    {
        $draft = InvoiceEmailDraft::of(
            ['ap@synthetic.test', '', '   '],
            [],
            'Invoice',
            null,
        );

        // An empty row from the compose form is not an address; keeping it would
        // fail the validation below and refuse the whole send.
        $this->assertSame(['ap@synthetic.test'], $draft->recipients);
    }

    public function test_the_recipient_list_is_a_list_after_filtering(): void
    {
        // Removing a middle entry leaves holes in the keys, and a JSON column
        // holding {"0":…,"2":…} is an object rather than the array every reader
        // of `recipients` expects.
        $draft = InvoiceEmailDraft::of(
            ['first@synthetic.test', '', 'third@synthetic.test'],
            [],
            'Invoice',
            null,
        );

        $this->assertSame([0, 1], array_keys($draft->recipients));
    }

    public function test_an_address_already_on_the_to_line_is_not_blind_copied(): void
    {
        $draft = InvoiceEmailDraft::of(
            ['ap@synthetic.test'],
            ['ap@synthetic.test', 'me@synthetic.test'],
            'Invoice',
            null,
        );

        // Otherwise the same person receives the message twice and cannot tell
        // which copy is which.
        $this->assertSame(['me@synthetic.test'], $draft->bcc);
        $this->assertSame([0], array_keys($draft->bcc));
    }

    public function test_the_subject_is_trimmed(): void
    {
        $draft = InvoiceEmailDraft::of(['ap@synthetic.test'], [], '  Invoice INV-1  ', null);

        $this->assertSame('Invoice INV-1', $draft->subject);
    }

    public function test_a_subject_of_only_whitespace_is_refused(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('An invoice email needs a subject.');

        InvoiceEmailDraft::of(['ap@synthetic.test'], [], "  \t ", null);
    }

    public function test_the_constructor_refuses_a_whitespace_subject_of_its_own_accord(): void
    {
        // `of()` trims before it constructs, so going through it can never
        // reach this guard - and a guard only one caller can trip is a guard
        // that stops holding the moment a second caller appears.
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('An invoice email needs a subject.');

        new InvoiceEmailDraft(['ap@synthetic.test'], [], "  \t ", null);
    }

    public function test_a_body_of_only_whitespace_becomes_no_body(): void
    {
        $draft = InvoiceEmailDraft::of(['ap@synthetic.test'], [], 'Invoice', "  \n ");

        // Null means "send the template's own words"; an empty string would put
        // a blank paragraph above the figures.
        $this->assertNull($draft->body);
    }

    public function test_a_body_is_trimmed_but_kept(): void
    {
        $draft = InvoiceEmailDraft::of(['ap@synthetic.test'], [], 'Invoice', "  Hello,\n\nThanks.  ");

        $this->assertSame("Hello,\n\nThanks.", $draft->body);
    }

    public function test_no_recipients_is_refused(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('At least one email recipient is required.');

        InvoiceEmailDraft::of([' ', ''], [], 'Invoice', null);
    }

    public function test_an_invalid_recipient_is_refused(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Every invoice recipient must be a valid email address.');

        InvoiceEmailDraft::of(['ap@synthetic.test', 'not-an-address'], [], 'Invoice', null);
    }

    public function test_an_invalid_blind_copy_is_refused_too(): void
    {
        // The blind copies are checked by the same loop as the recipients: a
        // bad address there fails the send just as completely, and validating
        // only the visible half is how that goes unnoticed until it happens.
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Every invoice recipient must be a valid email address.');

        InvoiceEmailDraft::of(['ap@synthetic.test'], ['not-an-address'], 'Invoice', null);
    }
}
