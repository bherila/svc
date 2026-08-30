<?php

namespace App\Support\Tenancy;

/**
 * One tenant-owned child column that points at another tenant-owned row.
 *
 * `enforced` says whether the database itself refuses a cross-workspace value
 * through a composite `(workspace_id, <column>)` foreign key. Where it does not,
 * `note` carries the reason, and the audit command still counts the column so an
 * exemption stays measured rather than merely asserted.
 */
final readonly class TenantReference
{
    public function __construct(
        public string $childTable,
        public string $childColumn,
        public string $parentTable,
        public bool $enforced,
        public ?string $constraintName,
        public string $note,
    ) {}

    public static function enforcedBy(string $childTable, string $childColumn, string $parentTable, string $constraintName): self
    {
        return new self($childTable, $childColumn, $parentTable, true, $constraintName, '');
    }

    public static function exempt(string $childTable, string $childColumn, string $parentTable, string $note): self
    {
        return new self($childTable, $childColumn, $parentTable, false, null, $note);
    }

    public function label(): string
    {
        return $this->childTable.'.'.$this->childColumn.' -> '.$this->parentTable;
    }
}
