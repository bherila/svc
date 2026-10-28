<?php

namespace App\Support\Billing;

use Throwable;

/** An acceptance, or a failure classified at the observed transport boundary. */
final readonly class InvoiceMailSendResult
{
    private function __construct(
        public ?string $providerMessageId,
        public ?Throwable $failure,
        public bool $handoffStarted,
    ) {}

    public static function accepted(?string $providerMessageId): self
    {
        return new self($providerMessageId, null, true);
    }

    public static function failed(Throwable $failure, bool $handoffStarted): self
    {
        return new self(null, $failure, $handoffStarted);
    }
}
