<?php

namespace App\Support\Billing;

use DomainException;

/** Date-only coverage policy, shared by generation and read-only audits. */
final readonly class AgreementHandover
{
    public function __construct(
        public string $successorId,
        public string $startsOn,
        public ?string $outgoingEndsOn,
    ) {}

    public function overlaps(string $start, string $end, bool $proratesAtAgreementEnd = false): bool
    {
        if ($proratesAtAgreementEnd && $this->outgoingEndsOn !== null) {
            $end = min($end, $this->outgoingEndsOn);
        }

        return $start <= $end && $end >= $this->startsOn;
    }

    public function assertClear(string $start, string $end, bool $proratesAtAgreementEnd = false): void
    {
        if ($this->overlaps($start, $end, $proratesAtAgreementEnd)) {
            throw new DomainException("The billing period overlaps successor agreement {$this->successorId}, starting {$this->startsOn}. Correct the agreement dates or schedule period before generating; no invoice has been changed.");
        }
    }
}
