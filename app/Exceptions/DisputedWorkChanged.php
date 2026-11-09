<?php

namespace App\Exceptions;

use DomainException;

/**
 * A dispute or its settlement changed the work a cadence draft was priced from
 * after the draft was generated (docs/ux-overhaul.md 7b″), so it is not issued.
 *
 * The row need not be on the draft: disputed historical work leaves the ledger,
 * and the capacity a later draft's charges were sized against moves with it.
 * The dispute normally rebuilds the drafts that depend on it; this is the
 * backstop for a rebuild that was refused or that did not run. Regenerating
 * the draft prices it from the current state, and it can then be issued.
 *
 * A `DomainException`, so the website reports it as a validation message, the
 * API as a 422 carrying {@see CODE} in its `code` field, and MCP as a tool
 * error naming the same code. Nothing is written before it is thrown.
 */
final class DisputedWorkChanged extends DomainException implements CodedRefusal
{
    public const string CODE = 'disputed_work_changed';

    public function __construct()
    {
        parent::__construct('A client dispute, or its settlement, changed the work this draft was priced from. Regenerate the draft, then issue it.');
    }

    public function refusalCode(): string
    {
        return self::CODE;
    }
}
