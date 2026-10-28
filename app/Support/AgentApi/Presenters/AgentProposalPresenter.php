<?php

namespace App\Support\AgentApi\Presenters;

use App\Models\ClientProposal;
use App\Models\ClientProposalItem;
use LogicException;

/** Pure fields over scoped, already-loaded proposal items. */
final class AgentProposalPresenter
{
    /** @return array<string, mixed> */
    public static function summary(ClientProposal $proposal): array
    {
        if (! $proposal->relationLoaded('items')) {
            throw new LogicException('Load the scoped proposal items before presenting a proposal.');
        }

        return ['id' => $proposal->public_id, 'title' => $proposal->title, 'status' => $proposal->status,
            'total_amount' => $proposal->totalAmount(), 'currency' => $proposal->currency];
    }

    /** @return array<string, mixed> */
    public static function details(ClientProposal $proposal, bool $iso8601Timestamps = false): array
    {
        return self::summary($proposal) + ['summary' => $proposal->summary, 'terms' => $proposal->terms,
            'valid_until' => $proposal->valid_until?->toDateString(),
            'sent_at' => $iso8601Timestamps ? $proposal->sent_at?->toIso8601String() : $proposal->sent_at?->toISOString(),
            'accepted_at' => $iso8601Timestamps ? $proposal->accepted_at?->toIso8601String() : $proposal->accepted_at?->toISOString()];
    }

    /** @return array<string, mixed> */
    public static function item(ClientProposalItem $item): array
    {
        return ['id' => $item->public_id, 'description' => $item->description,
            'quantity' => $item->quantity, 'unit_amount' => $item->unit_amount, 'cadence' => $item->cadence];
    }
}
