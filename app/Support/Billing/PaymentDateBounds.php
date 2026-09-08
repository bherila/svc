<?php

namespace App\Support\Billing;

use App\Services\Billing\InvoiceLifecycleService;
use App\Support\WorkspaceClock;
use Carbon\CarbonImmutable;
use DomainException;

/**
 * The window a payment may be dated into, and the only place that decides it.
 *
 * `client_invoice_payments.received_on` is not an audit timestamp - it is the
 * day the money arrived, and it is what an external finance system reconciles
 * by. Nothing downstream disagrees with a wrong one: `refreshStatus()` totals
 * *amounts* filtered by status and never reads this column, so a cheque dated
 * into the wrong month leaves the invoice's status, balance and paid amount
 * exactly right and puts the money in a period that has closed.
 *
 * So the bound is a domain rule rather than a form rule, and it lives where
 * every caller crosses. `svc:billing:payment`, an import and a hand-repair do
 * not pass through `StorePaymentRequest`; they do pass through
 * {@see InvoiceLifecycleService}.
 *
 * Dates are compared as `Y-m-d` strings rather than as instants, deliberately.
 * The format is fixed and zero-padded, so lexicographic order *is* chronological
 * order, and comparing strings keeps a calendar day from acquiring a time and a
 * zone on the way through a comparison - which is the whole class of defect
 * this column already has.
 */
final class PaymentDateBounds
{
    /**
     * How far back a payment may be dated, in years before the workspace's today.
     *
     * Two, because the alternatives are worse in both directions. With no floor
     * at all a mistyped year - `2015-09-04` for `2025-09-04` - is stored without
     * comment and is silently reconcilable a decade into the past, which is the
     * one wrong date nothing downstream can notice. Binding to the invoice's
     * `issue_date` instead would refuse a real arrangement: a deposit or an
     * advance retainer applied to an invoice issued afterwards predates it
     * legitimately, so that bound would refuse business rather than typos.
     *
     * Two years clears every genuinely late reconciliation this application has
     * a reason to record - a year-end catch-up, a re-recorded import, a cheque
     * found in a drawer - while a year typed one digit wrong lands far outside
     * it. It is a policy number, not a derived one; it is written here once so
     * that changing it is a decision made in one place.
     */
    public const FLOOR_YEARS_BEFORE_TODAY = 2;

    private function __construct(
        private readonly string $earliest,
        private readonly string $latest,
    ) {}

    /**
     * The window as of one workspace's today.
     *
     * The caller reads that today from {@see WorkspaceClock}, so
     * the window is the workspace's calendar rather than the server's or the
     * browser's - a payment "received tomorrow" is a claim about an event that
     * has not happened, and which day tomorrow is depends on whose clock is
     * asked.
     */
    public static function asOf(CarbonImmutable $today): self
    {
        $day = $today->startOfDay();

        return new self(
            // Clamped rather than overflowed. Two years before the 29th of
            // February is a day that does not exist, and PHP's default year
            // arithmetic answers the 1st of March - which makes the window a
            // day *shorter* on that one day of every fourth year, so a payment
            // dated exactly two calendar years earlier is refused for being
            // too old. The no-overflow form answers the 28th, which is the
            // reading that keeps the advertised window the advertised size and
            // errs towards accepting a real payment rather than refusing one.
            //
            // The only arithmetic here. `startOfDay()` cannot overflow a
            // calendar and the upper bound is today itself, so this is the one
            // place in this class where the question arises.
            $day->subYearsNoOverflow(self::FLOOR_YEARS_BEFORE_TODAY)->toDateString(),
            $day->toDateString(),
        );
    }

    /** The earliest acceptable payment date, as `Y-m-d`. */
    public function earliest(): string
    {
        return $this->earliest;
    }

    /** The latest acceptable payment date - today, on the workspace's calendar. */
    public function latest(): string
    {
        return $this->latest;
    }

    /**
     * Read a calendar date, without asking whether it may be recorded today.
     *
     * Parsed rather than compared, for the same reason payment status is: a
     * value this application cannot read is not a value it may guess at. A
     * non-string is refused by type instead of being stringified, `Y-m-d` is
     * the only spelling accepted, and `2026-02-31` is refused rather than
     * rolled forward into March.
     *
     * **Static, and separate from {@see self::assertWithin()}, because the two
     * questions have different lifetimes.** Whether a string is a calendar date
     * is a fact about the string and never changes. Whether that date may be
     * recorded is a fact about the clock, and stops being true as the clock
     * moves - a date on the floor today is below it tomorrow.
     *
     * Anything matching an already-recorded payment must therefore ask only the
     * first question. An idempotent retry compares what the caller sent against
     * what is stored, and that comparison cannot depend on the day the retry
     * happens or a lost-response retry stops being safe overnight. Only the
     * caller that is about to *create* a payment asks the second.
     */
    public static function calendarDate(mixed $raw): string
    {
        if (! is_string($raw)) {
            throw new DomainException(
                'A payment date must be a calendar date written as YYYY-MM-DD, and '
                .get_debug_type($raw).' is not one. Nothing has been changed.'
            );
        }

        $value = trim($raw);
        $parts = [];
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new DomainException(
                'A payment date must be a real calendar date written as YYYY-MM-DD. "'
                .$value.'" is not, and it is the column an external finance system '
                .'reconciles by. Nothing has been changed.'
            );
        }

        return $value;
    }

    /**
     * Refuse a calendar date this workspace may not record a payment on now.
     *
     * The time-relative half. Ask it at the moment a payment is being written
     * and not before: it is the answer that expires.
     */
    public function assertWithin(string $value): string
    {
        if ($value < $this->earliest) {
            throw new DomainException(
                'A payment cannot be dated before '.$this->earliest.'. "'.$value.'" is '
                .'further back than this workspace records payments, which reads as a '
                .'mistyped year rather than a payment that arrived. Nothing has been changed.'
            );
        }

        if ($value > $this->latest) {
            throw new DomainException(
                'A payment cannot be dated after '.$this->latest.', which is today on '
                .'this workspace\'s calendar. "'.$value.'" is a claim about money that '
                .'has not arrived. Nothing has been changed.'
            );
        }

        return $value;
    }

    /**
     * Both questions at once, for a caller that asks them at the same moment.
     *
     * The date correction is the one such caller: it neither creates a row nor
     * matches an existing one, so the shape and the window are decided together.
     */
    public function parse(mixed $raw): string
    {
        return $this->assertWithin(self::calendarDate($raw));
    }
}
