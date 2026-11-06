<?php

declare(strict_types=1);

namespace App\Support\Revenue;

/** A client company as the revenue reads name it: its public id and name. */
final readonly class RevenueClient
{
    public function __construct(
        public int $id,
        public string $publicId,
        public string $name,
        public bool $isActive,
    ) {}
}
