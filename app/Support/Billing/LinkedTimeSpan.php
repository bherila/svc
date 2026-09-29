<?php

namespace App\Support\Billing;

/**
 * The time one invoice line links: how much, how many entries, which days,
 * and how much of it was deferred work. Computed from rows already read.
 */
final readonly class LinkedTimeSpan
{
    private function __construct(
        public int $minutes,
        public int $entries,
        public ?string $firstWorkedOn,
        public ?string $lastWorkedOn,
        public int $deferredMinutes,
    ) {}

    /**
     * @param  iterable<array{minutes: int, worked_on: string, is_deferred: bool}>  $rows
     */
    public static function of(iterable $rows): self
    {
        $minutes = 0;
        $deferred = 0;
        $days = [];

        foreach ($rows as $row) {
            $minutes += $row['minutes'];
            if ($row['is_deferred']) {
                $deferred += $row['minutes'];
            }
            $days[] = $row['worked_on'];
        }

        return $days === []
            ? new self($minutes, 0, null, null, $deferred)
            : new self($minutes, count($days), min($days), max($days), $deferred);
    }

    public function describe(): string
    {
        return sprintf('linked %d min in %d entr%s', $this->minutes, $this->entries, $this->entries === 1 ? 'y' : 'ies')
            .($this->firstWorkedOn === null ? '' : sprintf(' worked %s..%s', $this->firstWorkedOn, $this->lastWorkedOn))
            .($this->deferredMinutes === 0 ? '' : sprintf(' (%d min deferred)', $this->deferredMinutes));
    }
}
