<?php

declare(strict_types=1);

namespace App\Support\Billing\Autopay;

/**
 * Everything {@see AutopayEligibility} needs to decide about one invoice,
 * already read. Calendar dates are `Y-m-d` strings in the workspace's own time
 * zone, so the decision compares text and needs no clock and no database.
 */
final readonly class AutopayFacts
{
    public function __construct(
        public string $invoiceStatus,
        public int $balance,
        public string $invoiceCurrency,
        public string $limitCurrency,
        public bool $visibleToClient,
        public ?string $dueOn,
        public string $today,
        public ?string $autopayEnabledOn,
        public bool $autopayOn,
        public ?int $limit,
        public bool $hasDefaultMethod,
        public ?AutopayAttemptState $priorAttempt,
        public bool $paymentInFlight,
        public bool $squareCollects,
        public bool $stripeCanCharge,
    ) {}

    /** The same facts as they will stand on the due date: the question "will it be charged?". */
    public function onDueDate(): self
    {
        return new self(
            $this->invoiceStatus,
            $this->balance,
            $this->invoiceCurrency,
            $this->limitCurrency,
            $this->visibleToClient,
            $this->dueOn,
            $this->dueOn ?? $this->today,
            $this->autopayEnabledOn,
            $this->autopayOn,
            $this->limit,
            $this->hasDefaultMethod,
            $this->priorAttempt,
            $this->paymentInFlight,
            $this->squareCollects,
            $this->stripeCanCharge,
        );
    }
}
