<?php

namespace App\Support\Billing;

use App\Services\Billing\InterimOverageGenerator;

/**
 * What an interim claim's ledger reads besides its time, as current rows read
 * with locks in registry order (#718): the client's other invoices, their
 * lines, and the allocations on those lines. Each part is a digest; the ids
 * carry one phase's rows to the next phase's query.
 *
 * {@see InterimOverageGenerator::assertClaimIssuable()}
 * compares it with the same reads through the transaction's snapshot.
 */
final readonly class InterimLedgerFingerprint
{
    /**
     * @param  list<int>  $invoiceIds
     * @param  list<int>  $lineIds
     */
    public function __construct(
        public string $invoices,
        public array $invoiceIds,
        public ?string $lines = null,
        public array $lineIds = [],
        public ?string $allocations = null,
    ) {}

    /** @param  list<int>  $lineIds */
    public function withLines(string $lines, array $lineIds): self
    {
        return new self($this->invoices, $this->invoiceIds, $lines, $lineIds);
    }

    public function withAllocations(string $allocations): self
    {
        return new self($this->invoices, $this->invoiceIds, $this->lines, $this->lineIds, $allocations);
    }
}
