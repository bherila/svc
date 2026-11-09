<?php

namespace App\Exceptions;

use DomainException;

/**
 * A draft of an agreement charges ordinary work dated on or after the day a
 * successor for the same scope took over, so it is not issued.
 *
 * Generation leaves that work to the successor, but a draft generated before
 * the successor was added, or before a backdated termination made room for
 * it, still carries what it selected then. Regenerating the draft prices it
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

    public function __construct(string $successorStart)
    {
        parent::__construct(sprintf(
            'This draft charges work dated from %s, when a successor agreement took over. Regenerate the draft, then issue it.',
            $successorStart,
        ));
    }

    public function refusalCode(): string
    {
        return self::CODE;
    }
}
