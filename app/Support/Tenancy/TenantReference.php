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
    /**
     * @param  bool  $parentMayBeAbsent  whether a populated column naming a row
     *                                   that no longer exists is legitimate. True only for
     *                                   lineage columns, whose whole purpose is to outlive
     *                                   what they name - for those, the audit asks whether an
     *                                   existing parent is in the wrong workspace rather than
     *                                   treating a permitted deletion as corruption.
     */
    public function __construct(
        public string $childTable,
        public string $childColumn,
        public string $parentTable,
        public bool $enforced,
        public ?string $constraintName,
        public string $note,
        public bool $parentMayBeAbsent = false,
    ) {}

    public static function enforcedBy(string $childTable, string $childColumn, string $parentTable, string $constraintName): self
    {
        return new self($childTable, $childColumn, $parentTable, true, $constraintName, '');
    }

    /**
     * Exempt from the composite key, but the parent must still exist: these
     * columns carry a single-column foreign key, so an absent parent is
     * corruption rather than a permitted deletion.
     */
    public static function exempt(string $childTable, string $childColumn, string $parentTable, string $note): self
    {
        return new self($childTable, $childColumn, $parentTable, false, null, $note);
    }

    /**
     * Exempt, and the named row may legitimately be gone.
     */
    public static function lineage(string $childTable, string $childColumn, string $parentTable, string $note): self
    {
        return new self($childTable, $childColumn, $parentTable, false, null, $note, true);
    }

    public function label(): string
    {
        return $this->childTable.'.'.$this->childColumn.' -> '.$this->parentTable;
    }
}
