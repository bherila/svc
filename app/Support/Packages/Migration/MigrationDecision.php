<?php

namespace App\Support\Packages\Migration;

/** What to do with one subscriber due to move: apply, skip for good, or hold for another run (MIG-3). */
final readonly class MigrationDecision
{
    private function __construct(
        public ?MigrationSkip $skip,
        public ?MigrationHold $hold,
    ) {}

    public static function apply(): self
    {
        return new self(null, null);
    }

    public static function skip(MigrationSkip $skip): self
    {
        return new self($skip, null);
    }

    public static function hold(MigrationHold $hold): self
    {
        return new self(null, $hold);
    }

    public function applies(): bool
    {
        return $this->skip === null && $this->hold === null;
    }
}
