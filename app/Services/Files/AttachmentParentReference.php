<?php

namespace App\Services\Files;

use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class AttachmentParentReference
{
    public function __construct(public string $type, public string $publicId, public ?string $companyId = null)
    {
        if (! in_array($type, AttachmentRecordResolver::readableTypes(), true) || ! Str::isUuid($publicId)
            || ($this->collaboration() && ($companyId === null || ! Str::isUuid($companyId)))
            || (! $this->collaboration() && $companyId !== null)) {
            throw new InvalidArgumentException('Invalid attachment parent reference.');
        }
    }

    public function collaboration(): bool
    {
        return in_array($this->type, ['topic', 'comment'], true);
    }
}
