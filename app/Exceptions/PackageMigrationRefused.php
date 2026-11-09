<?php

namespace App\Exceptions;

use App\Support\Packages\Migration\MigrationRefusal;
use DomainException;

/**
 * Scheduling or cancelling a move that the package, its subscribers or the
 * date do not allow (MIG-1, MIG-5). A `DomainException`, so the website shows
 * it as a validation message and the agent API answers 422 with its code.
 * Nothing is written before it is thrown.
 */
final class PackageMigrationRefused extends DomainException implements CodedRefusal
{
    public function __construct(public readonly MigrationRefusal $refusal, ?string $detail = null)
    {
        parent::__construct($detail === null ? $refusal->message() : $refusal->message().' '.$detail);
    }

    public static function because(MigrationRefusal $refusal, ?string $detail = null): self
    {
        return new self($refusal, $detail);
    }

    public function refusalCode(): string
    {
        return $this->refusal->value;
    }
}
