<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Every write to a payment row moves its revision.
 *
 * `payments.correct` refuses a correction made against a version the caller
 * read before the row changed, and the version is `lock_version`. Eloquent
 * saves move it through IncrementsAgentRevision; a query-builder update never
 * reaches that hook, so each one has to move it itself. This enumerates every
 * builder write to `client_invoice_payments` in the application and requires
 * it to - so a new one fails here rather than letting a stale correction in.
 *
 * Deletes are exempt: a deleted row has no version left to compare.
 */
final class PaymentRevisionWritersTest extends TestCase
{
    public function test_every_builder_update_of_a_payment_moves_its_revision(): void
    {
        $sites = [];
        foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/app')->name('*.php') as $file) {
            $source = $file->getContents();
            $offset = 0;
            while (preg_match('/ClientInvoicePayment::query\(\)|DB::table\(\s*[\'"]client_invoice_payments[\'"]\s*\)|->payments\(\)/', $source, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $start = $match[0][1];
                $end = strpos($source, ';', $start);
                $statement = substr($source, $start, ($end === false ? strlen($source) : $end) - $start);
                $offset = $start + strlen($match[0][0]);
                if (preg_match('/->(update|increment|decrement|upsert|updateOrInsert)\(/', $statement) !== 1) {
                    continue;
                }
                $sites[] = $file->getRelativePathname();
                $this->assertStringContainsString(
                    'lock_version',
                    $statement,
                    $file->getRelativePathname().' updates a payment without moving its revision: '.$statement,
                );
            }
        }

        // The scan found the writer this exists for, so an empty result cannot
        // pass for a clean one.
        $this->assertContains('Services/Billing/StripeWebhookService.php', $sites);
    }
}
