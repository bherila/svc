<?php

namespace App\Support\Finance;

use DomainException;

/** A per-run bound on provider calls, shared by all discovery and detail reads. */
final class ProcessorReadBudget
{
    private int $used = 0;

    private bool $refused = false;

    private readonly float $deadline;

    public function __construct(private readonly int $limit = 200, int $maxSeconds = 600)
    {
        if ($limit < 1 || $limit > 1000) {
            throw new DomainException('The processor read budget must be between 1 and 1000 calls.');
        }
        if ($maxSeconds < 0 || $maxSeconds > 600) {
            throw new DomainException('The processor run duration must be between 0 and 600 seconds.');
        }
        $this->deadline = hrtime(true) + $maxSeconds * 1_000_000_000;
    }

    public function consume(): void
    {
        if ($this->used >= $this->limit || hrtime(true) >= $this->deadline) {
            $this->refused = true;

            throw new DomainException('The processor read budget is exhausted; continue the sync in another run.');
        }
        $this->used++;
    }

    /** Only an actual denied consume sets this; using the final allowed call does not. */
    public function refused(): bool
    {
        return $this->refused;
    }

    public function used(): int
    {
        return $this->used;
    }
}
