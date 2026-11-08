<?php

namespace App\Support\Payments\Inventory;

/** A workspace as the owner knows it: its name and public id, never the row id. */
final readonly class WorkspaceRef
{
    public function __construct(public string $publicId, public string $name) {}

    /** @return array{public_id: string, name: string} */
    public function toArray(): array
    {
        return ['public_id' => $this->publicId, 'name' => $this->name];
    }

    public function label(): string
    {
        return $this->name.' ('.$this->publicId.')';
    }
}
