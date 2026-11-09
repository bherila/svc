<?php

namespace App\Exceptions;

use App\Support\Approval\DisputeHold;
use DomainException;

/**
 * A draft still charges hours or expenses a client has disputed, so it is not
 * issued (docs/ux-overhaul.md 7b″).
 *
 * The dispute normally rebuilds the draft without them. This is the door every
 * issue passes through - browser, command, API and MCP - for the draft whose
 * rebuild was refused (a buy-down settles the entry, say) or that was composed
 * before the dispute landed by a path that does not rebuild. The provider
 * settles each dispute, which rebuilds the draft, and issues again.
 *
 * A `DomainException`, so the website reports it as a validation message, the
 * API as a 422 carrying {@see CODE} in its `code` field, and MCP as a tool
 * error naming the same code. Nothing is written before it is thrown.
 */
final class DisputedRowsOnDraft extends DomainException implements CodedRefusal
{
    public const string CODE = DisputeHold::ISSUE_REFUSAL_CODE;

    public function __construct(public readonly int $hours, public readonly int $expenses)
    {
        parent::__construct(self::message($hours, $expenses));
    }

    public static function message(int $hours, int $expenses): string
    {
        $parts = array_filter([
            $hours > 0 ? $hours.' '.($hours === 1 ? 'time entry' : 'time entries') : null,
            $expenses > 0 ? $expenses.' '.($expenses === 1 ? 'expense' : 'expenses') : null,
        ]);

        return 'This draft still charges '.implode(' and ', $parts).' the client disputed. Respond to each dispute, which takes it off the draft or puts it back, then issue again.';
    }

    public function refusalCode(): string
    {
        return self::CODE;
    }
}
