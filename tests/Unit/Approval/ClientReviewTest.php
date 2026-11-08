<?php

namespace Tests\Unit\Approval;

use App\Support\Approval\ClientApprovalVerdict;
use App\Support\Approval\ClientReview;
use App\Support\Approval\DisputeSettlement;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class ClientReviewTest extends TestCase
{
    public function test_a_disputed_row_carries_the_note_and_says_whether_it_is_held(): void
    {
        $review = ClientReview::present(ClientApprovalVerdict::Disputed, true, 'Synthetic note', CarbonImmutable::parse('2026-10-02T09:00:00Z'), null, null, null);

        $this->assertSame([
            'state' => 'disputed', 'state_label' => 'Disputed by the client', 'held' => true,
            'dispute_note' => 'Synthetic note', 'disputed_at' => '2026-10-02T09:00:00+00:00',
            'settlement' => null, 'settlement_label' => null, 'reply' => null, 'settled_at' => null,
        ], $review);
        $this->assertFalse(ClientReview::present(ClientApprovalVerdict::Disputed, false, null, null, null, null, null)['held'],
            'An issued charge is not held.');
    }

    public function test_only_a_disputed_row_is_held_or_shows_a_note(): void
    {
        $review = ClientReview::present(ClientApprovalVerdict::Pending, true, 'Stale note', CarbonImmutable::parse('2026-10-02'), null, null, null);

        $this->assertSame('undecided', $review['state']);
        $this->assertFalse($review['held']);
        $this->assertNull($review['dispute_note']);
        $this->assertNull($review['disputed_at']);
    }

    public function test_a_settlement_shows_its_reply_and_when(): void
    {
        $review = ClientReview::present(ClientApprovalVerdict::WrittenOff, false, null, null, DisputeSettlement::WrittenOff, 'Synthetic reply', CarbonImmutable::parse('2026-10-03T10:00:00Z'));

        $this->assertSame('written_off', $review['state']);
        $this->assertSame('written_off', $review['settlement']);
        $this->assertSame('Written off', $review['settlement_label']);
        $this->assertSame('Synthetic reply', $review['reply']);
        $this->assertSame('2026-10-03T10:00:00+00:00', $review['settled_at']);
        $this->assertNull(ClientReview::present(ClientApprovalVerdict::Pending, false, null, null, null, 'Orphan reply', CarbonImmutable::now())['reply'],
            'No settlement, no reply.');
    }

    public function test_every_verdict_has_its_own_state_and_label(): void
    {
        $this->assertSame(['undecided', 'approved', 'approved_by_payment', 'disputed', 'written_off'], ClientReview::states());
        $labels = array_map(fn (ClientApprovalVerdict $verdict): string => ClientReview::present($verdict, false, null, null, null, null, null)['state_label'], ClientApprovalVerdict::cases());
        $this->assertSame($labels, array_values(array_unique($labels)));
    }
}
