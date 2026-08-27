<?php

namespace Tests\Feature\Billing;

use App\Support\Billing\InvoiceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The invoice status vocabulary lives in one place.
 *
 * The predecessor's column was `enum('draft','issued','paid','void')`. This
 * schema uses a varchar and adds `partially_paid`, so every list ported from
 * that world is missing a value - and misses it silently, by omitting an
 * invoice from a guard rather than by failing.
 *
 * That produced the same defect four separate times: a partially paid invoice
 * could be reset to draft and rebuilt, a partially paid cadence invoice did not
 * block interim generation, a partially paid interim was regenerated, and a
 * partially paid retainer period could be sold twice. Three of those were found
 * only by diffing the two schemas after the fourth was reported.
 *
 * This test fails when a billing service starts writing its own list again.
 */
final class InvoiceStatusVocabularyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Services that decide what may be billed, regenerated or collected. These
     * are the ones where an incomplete list costs money.
     *
     * @var list<string>
     */
    private const GUARDED = [
        'app/Services/Billing/ClientInvoicingService.php',
        'app/Services/Billing/InterimOverageGenerator.php',
        'app/Services/Billing/InvoiceLineComposer.php',
        'app/Services/Billing/OverpaymentCreditService.php',
        'app/Services/Billing/InvoiceEmailService.php',
        'app/Services/Billing/StripePaymentIntentService.php',
        'app/Models/ClientInvoice.php',
    ];

    /**
     * A literal list of two or more invoice statuses is the shape that goes
     * stale. Single comparisons against one status are left alone: `!= 'void'`
     * says something specific and stays correct when a status is added.
     */
    public function test_no_billing_service_enumerates_invoice_statuses_by_hand(): void
    {
        $offenders = [];

        foreach (self::GUARDED as $relative) {
            $contents = file_get_contents(base_path($relative));
            if ($contents === false) {
                $this->fail("Could not read {$relative}");
            }

            foreach (explode("\n", $contents) as $number => $line) {
                if (preg_match_all("/'(draft|issued|partially_paid|paid|void)'/", $line, $matches) >= 1
                    && count($matches[1]) >= 2) {
                    $offenders[] = sprintf('%s:%d  %s', $relative, $number + 1, trim($line));
                }
            }
        }

        $this->assertSame([], $offenders, sprintf(
            'These lines enumerate invoice statuses by hand. A list written here goes stale the '.
            "moment a status is added, and it fails silently - by omitting an invoice from a guard.\n\n%s\n\n".
            'Use InvoiceStatus::settled(), ::charged(), ::collectible() or ::live() instead.',
            implode("\n", $offenders),
        ));
    }

    /**
     * The enum has to match the values the application actually stores, or the
     * indirection just moves the staleness somewhere less visible.
     */
    public function test_the_enum_covers_every_status_the_schema_allows(): void
    {
        $this->assertSame(
            ['draft', 'issued', 'partially_paid', 'paid', 'void'],
            InvoiceStatus::all(),
        );

        // Long enough to hold the longest value, so none is silently truncated.
        $this->assertTrue(Schema::hasColumn('client_invoices', 'status'));
        $longest = max(array_map('strlen', InvoiceStatus::all()));
        $this->assertLessThanOrEqual(32, $longest);
    }

    public function test_the_named_sets_stay_consistent_with_each_other(): void
    {
        // Everything charged is also settled; the reverse is not true, because
        // a void invoice is settled without ever having charged anyone.
        foreach (InvoiceStatus::charged() as $status) {
            $this->assertContains($status, InvoiceStatus::settled());
        }
        $this->assertContains('void', InvoiceStatus::settled());
        $this->assertNotContains('void', InvoiceStatus::charged());

        // Collectible means money is still owed, so a fully paid invoice is out.
        foreach (InvoiceStatus::collectible() as $status) {
            $this->assertContains($status, InvoiceStatus::charged());
        }
        $this->assertNotContains('paid', InvoiceStatus::collectible());

        // A draft has neither charged nor settled.
        $this->assertNotContains('draft', InvoiceStatus::settled());
        $this->assertNotContains('draft', InvoiceStatus::charged());

        // Live is everything that happened.
        $this->assertNotContains('void', InvoiceStatus::live());
        $this->assertContains('draft', InvoiceStatus::live());
    }

    public function test_an_unrecognised_stored_value_reads_as_the_least_privileged_state(): void
    {
        $this->assertSame(InvoiceStatus::Draft, InvoiceStatus::fromStored('something_new'));
        $this->assertSame(InvoiceStatus::Draft, InvoiceStatus::fromStored(null));
        $this->assertFalse(InvoiceStatus::fromStored('nonsense')->isSettled());
    }
}
