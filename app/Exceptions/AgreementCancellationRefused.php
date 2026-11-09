<?php

namespace App\Exceptions;

use App\Support\Engagement\Cancellation\CancellationRefusal;
use DomainException;

/**
 * A cancellation, a decision on one or an undo that the agreement's state or
 * terms do not allow (CAN-2 to CAN-4, EDGE-9). A `DomainException`, so the
 * website reports it as a validation message and the agent API as a 422 with
 * its stable code.
 */
final class AgreementCancellationRefused extends DomainException implements CodedRefusal
{
    public function __construct(public readonly CancellationRefusal $refusal)
    {
        parent::__construct($refusal->message());
    }

    public static function because(CancellationRefusal $refusal): self
    {
        return new self($refusal);
    }

    public function refusalCode(): string
    {
        return $this->refusal->value;
    }
}
