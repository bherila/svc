<?php

declare(strict_types=1);

namespace App\Support\Revenue;

/** Minor units per band for one client and month, before tax. */
final readonly class BandAmounts
{
    public function __construct(
        public int $recurring = 0,
        public int $overageAndOther = 0,
        public int $expenses = 0,
    ) {}

    public function plus(RevenueBand $band, int $amount): self
    {
        return match ($band) {
            RevenueBand::Recurring => new self($this->recurring + $amount, $this->overageAndOther, $this->expenses),
            RevenueBand::OverageAndOther => new self($this->recurring, $this->overageAndOther + $amount, $this->expenses),
            RevenueBand::Expenses => new self($this->recurring, $this->overageAndOther, $this->expenses + $amount),
        };
    }

    public function add(self $other): self
    {
        return new self($this->recurring + $other->recurring, $this->overageAndOther + $other->overageAndOther, $this->expenses + $other->expenses);
    }

    public function total(bool $includeExpenses): int
    {
        return $this->recurring + $this->overageAndOther + ($includeExpenses ? $this->expenses : 0);
    }

    public function isZero(): bool
    {
        return $this->recurring === 0 && $this->overageAndOther === 0 && $this->expenses === 0;
    }
}
