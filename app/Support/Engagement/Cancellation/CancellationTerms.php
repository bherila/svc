<?php

namespace App\Support\Engagement\Cancellation;

use App\Models\ClientAgreement;
use App\Support\Billing\BillingCadence;
use App\Support\Engagement\CancellationDates;
use App\Support\Engagement\CancellationEndDates;
use App\Support\Packages\CancellationMode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * An agreement's cancellation terms (CAN-1) and the facts that fix its end
 * dates, read off the agreement once so the dates offered are worked out
 * without a database (CAN-2).
 *
 * `offer()` asks {@see CancellationDates}, so an end only ever falls on a
 * close of the billing engine's own periods. An agreement that does not bill
 * in periods (`one_time`) has none to offer.
 */
final readonly class CancellationTerms
{
    public function __construct(
        public int $noticeDays,
        public ?int $minimumTermMonths,
        public CancellationMode $mode,
        /** Null when the agreement does not bill on a recurring cadence. */
        public ?BillingCadence $cadence,
        public CarbonImmutable $startsOn,
        public ?CarbonImmutable $endsOn,
    ) {}

    public static function of(ClientAgreement $agreement): self
    {
        // Defaults read on their own, so the casts below can be marked
        // equivalent without hiding the defaults' own mutants (#673).
        $noticeDays = $agreement->notice_days ?? 0;
        $mode = $agreement->cancellation_mode ?? '';

        return new self(
            noticeDays: /** @infection-ignore-all Equivalent: the model's integer cast returns an int for every stored value and the default is 0, so the cast changes nothing. */ (int) $noticeDays,
            minimumTermMonths: $agreement->minimum_term_months === null ? null : /** @infection-ignore-all Equivalent: the model's integer cast already returns an int for every non-null value, and the int parameter would coerce one in this non-strict file. */ (int) $agreement->minimum_term_months,
            mode: CancellationMode::tryFrom(/** @infection-ignore-all Equivalent: the column is a string and the default is '', so the cast changes nothing. */ (string) $mode) ?? CancellationMode::None,
            cadence: $agreement->billsOnARecurringCadence() ? $agreement->effectiveBillingCadence() : null,
            startsOn: $agreement->starts_on,
            endsOn: $agreement->ends_on,
        );
    }

    public function recurring(): bool
    {
        return $this->cadence !== null;
    }

    /**
     * The end dates a cancellation may choose on `$today` (the workspace's),
     * or null when the agreement does not bill in periods. Waiving notice
     * counts the notice as zero days; the minimum term still holds.
     *
     * `$currentEndsOn` is the end the agreement has without this cancellation,
     * by default its own `ends_on`; no date on or after it is offered.
     */
    public function offer(CarbonInterface $today, bool $waiveNotice = false, ?CarbonInterface $currentEndsOn = null, CancellationDates $dates = new CancellationDates): ?CancellationEndDates
    {
        if ($this->cadence === null) {
            return null;
        }

        return $dates->offer(
            $this->cadence,
            $this->startsOn,
            $waiveNotice ? 0 : $this->noticeDays,
            $this->minimumTermMonths,
            $today,
            $currentEndsOn ?? $this->endsOn,
        );
    }
}
