<?php

namespace App\Support\Finance;

/** Fixed outcomes contain no identifiers, amounts or provider payloads. */
enum ProcessorMovementRecordOutcome: string
{
    case Created = 'created';
    case Unchanged = 'unchanged';
    case Enriched = 'enriched';
    case Conflict = 'conflict';

    public function needsReview(): bool
    {
        return $this === self::Conflict;
    }

    public function reason(): ?string
    {
        return $this === self::Conflict ? 'immutable_facts_changed' : null;
    }
}
