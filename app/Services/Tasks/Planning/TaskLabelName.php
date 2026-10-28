<?php

declare(strict_types=1);

namespace App\Services\Tasks\Planning;

use DomainException;

/** The same display name and ASCII uniqueness key on every database adapter. */
final readonly class TaskLabelName
{
    private function __construct(public string $name, public string $key) {}

    public static function fromInput(mixed $input): self
    {
        if (! is_string($input) || ! mb_check_encoding($input, 'UTF-8')) {
            throw new DomainException('Enter a label name with 1 to 80 characters.');
        }
        $name = mb_trim($input, encoding: 'UTF-8');
        if ($name === '' || mb_strlen($name, 'UTF-8') > 80 || preg_match('/[\p{Cc}\x{2028}\x{2029}]/u', $name) === 1) {
            throw new DomainException('Enter a single-line label name with 1 to 80 characters.');
        }

        return new self($name, hash('sha256', mb_strtolower($name, 'UTF-8')));
    }
}
