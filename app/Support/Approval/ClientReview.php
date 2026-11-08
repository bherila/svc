<?php

declare(strict_types=1);

namespace App\Support\Approval;

use Carbon\CarbonInterface;

/**
 * Where a row stands with the client, as the provider reads it
 * (docs/ux-overhaul.md 7b″): the client's decision, whether a dispute holds it
 * out of billing, and how the provider settled the last dispute.
 *
 * Read from the same verdict the client's own screen reads, so the operator
 * and the client never disagree about a row. Pure and database-free: the
 * caller supplies the facts it already loaded.
 */
final readonly class ClientReview
{
    /**
     * @param  bool  $held  disputed, and no charged invoice carries it ({@see DisputeHold})
     * @return array{state: string, state_label: string, held: bool, dispute_note: string|null, disputed_at: string|null, settlement: string|null, settlement_label: string|null, reply: string|null, settled_at: string|null}
     */
    public static function present(
        ClientApprovalVerdict $verdict,
        bool $held,
        ?string $disputeNote,
        ?CarbonInterface $disputedAt,
        ?DisputeSettlement $settlement,
        ?string $reply,
        ?CarbonInterface $settledAt,
    ): array {
        $disputed = $verdict === ClientApprovalVerdict::Disputed;

        return [
            'state' => self::state($verdict),
            'state_label' => self::label($verdict),
            'held' => $disputed && $held,
            'dispute_note' => $disputed ? $disputeNote : null,
            'disputed_at' => $disputed ? $disputedAt?->toIso8601String() : null,
            'settlement' => $settlement?->value,
            'settlement_label' => $settlement?->label(),
            'reply' => $settlement === null ? null : $reply,
            'settled_at' => $settlement === null ? null : $settledAt?->toIso8601String(),
        ];
    }

    /** @return list<string> every state, for the schema */
    public static function states(): array
    {
        return array_values(array_unique(array_map(self::state(...), ClientApprovalVerdict::cases())));
    }

    private static function state(ClientApprovalVerdict $verdict): string
    {
        return match ($verdict) {
            ClientApprovalVerdict::Pending => 'undecided',
            ClientApprovalVerdict::Approved => 'approved',
            ClientApprovalVerdict::ApprovedByPayment => 'approved_by_payment',
            ClientApprovalVerdict::Disputed => 'disputed',
            ClientApprovalVerdict::WrittenOff => 'written_off',
        };
    }

    private static function label(ClientApprovalVerdict $verdict): string
    {
        return match ($verdict) {
            ClientApprovalVerdict::Pending => 'Waiting for the client',
            ClientApprovalVerdict::Approved => 'Approved by the client',
            ClientApprovalVerdict::ApprovedByPayment => 'Approved by payment',
            ClientApprovalVerdict::Disputed => 'Disputed by the client',
            ClientApprovalVerdict::WrittenOff => 'Written off',
        };
    }
}
