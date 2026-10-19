<?php

namespace App\Support\Finance;

use Carbon\CarbonImmutable;
use DomainException;

/**
 * What a payment processor reports about settling one payment: when it
 * charged it, its fee (in `$feeCurrency`, positive for a fee charged), and the
 * payout the payment settled in with that payout's arrival day.
 *
 * Null means "not reported here", never "clear it": a report fills what it
 * knows and leaves the rest. `fromPayout` says the fee came from the payout's
 * own accounting, which a later charge-time estimate does not overwrite.
 */
final readonly class PaymentSettlement
{
    private function __construct(
        public ?CarbonImmutable $chargedAt,
        public ?int $feeAmount,
        public ?string $feeCurrency,
        public ?string $payoutIdentifier,
        public ?string $payoutArrivalDate,
        public bool $fromPayout,
        /** How current the payout report is (epoch ms); null for a charge-time report. */
        public ?int $payoutReportedAtMs = null,
    ) {
        if (($feeAmount === null) !== ($feeCurrency === null)) {
            throw new DomainException('A processor fee is reported with its currency.');
        }
        if ($feeCurrency !== null && preg_match('/^[A-Z]{3}$/D', $feeCurrency) !== 1) {
            throw new DomainException('A processor fee currency is three upper-case letters.');
        }
        if ($payoutIdentifier !== null && ($payoutIdentifier === '' || strlen($payoutIdentifier) > 255)) {
            throw new DomainException('A payout identifier is between 1 and 255 characters.');
        }
        if ($payoutArrivalDate !== null && ($payoutIdentifier === null || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $payoutArrivalDate) !== 1)) {
            throw new DomainException('A payout arrival date is Y-m-d and belongs to a payout.');
        }
    }

    /** What is known when the payment is charged, before any payout. */
    public static function charge(?CarbonImmutable $chargedAt, ?int $feeAmount, ?string $feeCurrency): self
    {
        return new self($chargedAt?->utc(), $feeAmount, $feeCurrency, null, null, false);
    }

    /** What a payout's accounting says about one of its payments. */
    public static function payout(string $payoutIdentifier, ?string $arrivalDate, int $reportedAtMs, ?CarbonImmutable $chargedAt, ?int $feeAmount, ?string $feeCurrency): self
    {
        if ($reportedAtMs < 0) {
            throw new DomainException('A payout report time is not before the epoch.');
        }

        return new self($chargedAt?->utc(), $feeAmount, $feeCurrency, $payoutIdentifier, $arrivalDate, true, $reportedAtMs);
    }
}
