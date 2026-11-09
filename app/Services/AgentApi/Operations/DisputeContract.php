<?php

namespace App\Services\AgentApi\Operations;

use App\Services\Approval\DisputeSettlements;
use App\Support\Approval\ClientReview;
use App\Support\Approval\DisputeSettlement;
use InvalidArgumentException;

/**
 * The code-owned OpenAPI components of disputes (docs/ux-overhaul.md 7b″),
 * regenerated into the public contract by scripts/generate-dispute-contract.php:
 * the `client_review` every time entry and expense carries, and the request
 * that settles a dispute. The operations are declared beside the approvals in
 * {@see AgentOperationCatalog} and {@see AgentRestDocumentation}.
 */
final class DisputeContract
{
    /** @return list<string> */
    public static function components(): array
    {
        return ['ClientReview', 'DisputeSettleRequest'];
    }

    /**
     * The hand-kept row components that carry a `client_review`.
     *
     * @return list<string>
     */
    public static function reviewedRows(): array
    {
        return ['TimeEntry', 'Expense'];
    }

    /** @return array<string, mixed> */
    public static function schema(string $component): array
    {
        $nullableText = ['type' => ['string', 'null']];
        $instant = ['type' => ['string', 'null'], 'format' => 'date-time'];

        return [
            'ClientReview' => [
                'type' => 'object', 'additionalProperties' => false,
                'description' => 'Where the row stands with the client: their decision, whether a dispute holds it out of billing, and how the provider settled the last dispute.',
                'required' => ['state', 'state_label', 'held', 'dispute_note', 'disputed_at', 'settlement', 'settlement_label', 'reply', 'settled_at'],
                'properties' => [
                    'state' => ['type' => 'string', 'enum' => ClientReview::states(), 'description' => 'undecided: waiting for the client (it still bills on schedule). approved: the client approved it. approved_by_payment: the invoice carrying it is paid in full. disputed: the client disputed it. written_off: a dispute was settled by never billing it.'],
                    'state_label' => ['type' => 'string'],
                    'held' => ['type' => 'boolean', 'description' => 'True while a dispute keeps the row off every draft: disputed, and no issued invoice already charges it.'],
                    'dispute_note' => $nullableText,
                    'disputed_at' => $instant,
                    'settlement' => ['type' => ['string', 'null'], 'enum' => [...DisputeSettlement::values(), null]],
                    'settlement_label' => $nullableText,
                    'reply' => ['type' => ['string', 'null'], 'description' => 'The provider\'s reply to the last dispute, as the client reads it.'],
                    'settled_at' => $instant,
                ],
            ],
            'DisputeSettleRequest' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['expected_version', 'outcome', 'reply', 'confirm'],
                'properties' => [
                    'expected_version' => ['$ref' => '#/components/schemas/Version'],
                    'outcome' => ['type' => 'string', 'enum' => DisputeSettlement::values(), 'description' => 'answered: clear the dispute and offer the row to the client again; it bills on the next draft. written_off: never bill it.'],
                    'reply' => ['type' => 'string', 'minLength' => 1, 'maxLength' => DisputeSettlements::REPLY_MAX, 'description' => 'What the client reads. Only what the user wrote.'],
                    'confirm' => ['type' => 'boolean', 'const' => true, 'description' => 'The user explicitly confirmed this settlement and its reply.'],
                ],
            ],
        ][$component] ?? throw new InvalidArgumentException("Unknown dispute component {$component}.");
    }
}
