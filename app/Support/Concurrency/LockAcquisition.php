<?php

namespace App\Support\Concurrency;

final readonly class LockAcquisition
{
    public function __construct(
        public LockResource $resource,
        public LockMode $mode,
        public string $file,
        public int $line,
    ) {}
}
