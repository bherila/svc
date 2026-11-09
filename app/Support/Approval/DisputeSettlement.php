<?php

declare(strict_types=1);

namespace App\Support\Approval;

/**
 * How the provider settled a client's dispute (docs/ux-overhaul.md 7b″).
 *
 * Only the provider's side settles; a client contact or reviewer approves or
 * disputes. Each settlement keeps a reply for the client to read. Every reader
 * of this enum matches it exhaustively: a new way of settling has to decide,
 * everywhere, whether the row bills.
 */
enum DisputeSettlement: string
{
    /** The dispute is cleared and the row offered to the client again; it bills on the next draft. */
    case Answered = 'answered';

    /** The row is never billed. */
    case WrittenOff = 'written_off';

    /** Does the row bill once settled this way? */
    public function bills(): bool
    {
        return match ($this) {
            self::Answered => true,
            self::WrittenOff => false,
        };
    }

    /** Who may settle a dispute this way: anyone who may edit the row, or only who may approve it. */
    public function needsApprover(): bool
    {
        return match ($this) {
            self::Answered => false,
            self::WrittenOff => true,
        };
    }

    /** The word the client and the operator read. */
    public function label(): string
    {
        return match ($this) {
            self::Answered => 'Answered',
            self::WrittenOff => 'Written off',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
