<?php

namespace Tests\Unit\Billing;

use App\Models\ClientTimeEntry;
use App\Services\Billing\Balances\DeferredAllocationResult;
use App\Services\Billing\Balances\DeferredEntryCandidate;
use PHPUnit\Framework\TestCase;

class DeferredEntryCandidateTest extends TestCase
{
    public function test_from_entry_converts_minutes_to_hours(): void
    {
        $entry = new ClientTimeEntry;
        $entry->id = 42;
        $entry->minutes = 90; // 1.5 h

        $candidate = DeferredEntryCandidate::fromEntry($entry);

        $this->assertSame(42, $candidate->id());
        $this->assertEqualsWithDelta(1.5, $candidate->hours, 0.0001);
        $this->assertSame($entry, $candidate->entry);
    }

    public function test_fractional_minutes_round_to_four_decimals(): void
    {
        $entry = new ClientTimeEntry;
        $entry->id = 1;
        $entry->minutes = 37; // 0.6166... h

        $candidate = DeferredEntryCandidate::fromEntry($entry);

        $this->assertEqualsWithDelta(0.6167, $candidate->hours, 0.0001);
    }

    public function test_empty_result_is_empty(): void
    {
        $result = DeferredAllocationResult::empty();
        $this->assertSame([], $result->billed);
        $this->assertSame([], $result->skipped);
        $this->assertFalse($result->hasBilled());
    }
}
