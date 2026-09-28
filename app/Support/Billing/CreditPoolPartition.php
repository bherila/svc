<?php

namespace App\Support\Billing;

/**
 * One workspace/company/currency credit pool as the audit found it.
 *
 * Amounts are integer minor units. A partition carrying any problem is
 * unevaluable: its funded, consumed and difference figures are then `null`
 * rather than a number, because a figure computed around unreadable rows
 * would state a balance nobody can vouch for.
 */
final class CreditPoolPartition
{
    private int $funded = 0;

    private int $consumed = 0;

    /** @var array<string, int> */
    private array $problems = [];

    public function __construct(
        public readonly int $workspaceId,
        public readonly ?int $companyId,
        public readonly string $currency,
    ) {}

    public function fund(int $minor): void
    {
        $this->funded += $minor;
    }

    public function consume(int $minor): void
    {
        $this->consumed += $minor;
    }

    public function flag(string $reason): void
    {
        $this->problems[$reason] = ($this->problems[$reason] ?? 0) + 1;
    }

    /**
     * Whether this pool has anything to report: credit funded, credit spent,
     * or a problem. An invoice paid exactly, or with only failed or pending
     * attempts, touches none of those and is ordinary billing, not a pool.
     */
    public function hasActivity(): bool
    {
        return $this->funded > 0 || $this->consumed > 0 || $this->problems !== [];
    }

    public function evaluable(): bool
    {
        return $this->problems === [];
    }

    public function funded(): ?int
    {
        return $this->evaluable() ? $this->funded : null;
    }

    public function consumed(): ?int
    {
        return $this->evaluable() ? $this->consumed : null;
    }

    /** Signed: funded minus consumed. Negative is a deficit. */
    public function difference(): ?int
    {
        return $this->evaluable() ? $this->funded - $this->consumed : null;
    }

    /** How far consumption exceeds funding, or zero. */
    public function deficit(): ?int
    {
        $difference = $this->difference();

        return $difference === null ? null : max(0, -$difference);
    }

    /** @return array<string, int> */
    public function problems(): array
    {
        ksort($this->problems);

        return $this->problems;
    }
}
