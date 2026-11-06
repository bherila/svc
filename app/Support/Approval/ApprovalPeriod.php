<?php

declare(strict_types=1);

namespace App\Support\Approval;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The span a client approves in: one calendar month, whatever the agreement's
 * billing cadence (docs/ux-overhaul.md, answered question 2).
 *
 * Pure and database-free. A period is named by `YYYY-MM`; the days it holds
 * are `YYYY-MM-01` to the month's last day, both inclusive, so a query bounds
 * a date column with {@see start()} and {@see end()} and never with a time.
 */
final readonly class ApprovalPeriod
{
    private function __construct(private CarbonImmutable $first) {}

    /** The period holding a `Y-m-d` day. */
    public static function containing(string $day): self
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $day);
        if (! $parsed instanceof CarbonImmutable || $parsed->toDateString() !== $day) {
            throw new InvalidArgumentException('An approval period is found from a Y-m-d day.');
        }

        return new self($parsed->startOfMonth());
    }

    /** The period a `YYYY-MM` key names, or null for anything else. */
    public static function fromKey(string $key): ?self
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $key) !== 1) {
            return null;
        }

        $first = CarbonImmutable::createFromFormat('!Y-m-d', $key.'-01');

        return $first instanceof CarbonImmutable ? new self($first) : null;
    }

    public function key(): string
    {
        return $this->first->format('Y-m');
    }

    /** First day, as `Y-m-d`. */
    public function start(): string
    {
        return $this->first->toDateString();
    }

    /** Last day, as `Y-m-d`. */
    public function end(): string
    {
        return $this->first->endOfMonth()->toDateString();
    }

    /** The key of the month before this one: `2026-01` is followed back by `2025-12`. */
    public function previousKey(): string
    {
        return $this->first->subMonthNoOverflow()->format('Y-m');
    }

    /** The key of the month after this one. */
    public function nextKey(): string
    {
        return $this->first->addMonthNoOverflow()->format('Y-m');
    }

    /** "October 2026". */
    public function label(): string
    {
        return $this->first->format('F Y');
    }

    public function holds(string $day): bool
    {
        return $day >= $this->start() && $day <= $this->end();
    }
}
