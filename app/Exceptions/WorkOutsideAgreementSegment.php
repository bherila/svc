<?php

namespace App\Exceptions;

use DomainException;

/**
 * A draft of an agreement charges ordinary work outside its segment - dated on
 * or after the day a successor for the same scope took over, or before the day
 * this agreement took over from a predecessor - so it is not issued.
 *
 * Generation leaves that work to the other agreement, but a draft generated
 * before the handover was recorded - a successor or predecessor added later,
 * or a backdated termination - still carries what it selected then. Regenerating the draft prices it
 * from the corrected history, and the successor's own invoices bill that work
 * at its terms.
 *
 * A `DomainException`, so the website reports it as a validation message, the
 * API as a 422 carrying {@see CODE} in its `code` field, and MCP as a tool
 * error naming the same code. Nothing is written before it is thrown.
 */
final class WorkOutsideAgreementSegment extends DomainException implements CodedRefusal
{
    public const string CODE = 'work_outside_agreement_segment';

    /** @param  string  $outside  when the work is, e.g. "from 2026-05-16, when a successor agreement took over" */
    public function __construct(string $outside)
    {
        parent::__construct(sprintf('This draft charges work dated %s. Regenerate the draft, then issue it.', $outside));
    }

    public function refusalCode(): string
    {
        return self::CODE;
    }
}
