<?php

declare(strict_types=1);

namespace App\Support\Billing;

/**
 * What a generated cadence draft was priced from, as far as disputes go
 * (docs/ux-overhaul.md 7b″).
 *
 * A dispute holds a row out of the ledger, and settling it puts it back or
 * keeps it out. Either can change the capacity a later draft's charges were
 * sized against without touching anything the draft links: the row is
 * historical, unlinked work. So the draft records the dispute and settlement
 * state of its scope's time through the last day its ledger read, and issuing
 * compares that with the state as it is now.
 *
 * Pure and database-free: the caller reads the rows.
 */
final readonly class HeldWorkBasis
{
    private const int VERSION = 1;

    private function __construct(
        public ?int $projectId,
        public string $through,
        public string $digest,
    ) {}

    /**
     * @param  int|null  $projectId  the agreement's project, or null for the whole company
     * @param  string  $through  `Y-m-d`, the last day the ledger read
     * @param  iterable<array{int, bool, string|null, bool}>  $rows  id, disputed, settlement, deleted - in id order
     */
    public static function of(?int $projectId, string $through, iterable $rows): self
    {
        return new self($projectId, $through, self::digest($rows));
    }

    /** @param  iterable<array{int, bool, string|null, bool}>  $rows */
    public static function digest(iterable $rows): string
    {
        $context = hash_init('sha256');
        foreach ($rows as [$id, $disputed, $settlement, $deleted]) {
            hash_update($context, $id.'|'.($disputed ? 'd' : '-').'|'.($settlement ?? '-').'|'.($deleted ? 'x' : '-')."\n");
        }

        return hash_final($context);
    }

    /**
     * The stored basis, or null when there is none, or none this version can
     * read: an invoice generated before the basis existed is issued as before.
     *
     * @param  array<string, mixed>|null  $stored
     */
    public static function fromStored(?array $stored): ?self
    {
        if ($stored === null || ($stored['version'] ?? null) !== self::VERSION) {
            return null;
        }
        $projectId = $stored['project_id'] ?? null;
        $through = $stored['through'] ?? null;
        $digest = $stored['digest'] ?? null;
        if (! ($projectId === null || is_int($projectId)) || ! is_string($through) || ! is_string($digest)) {
            return null;
        }

        return new self($projectId, $through, $digest);
    }

    /** @return array{version: int, project_id: int|null, through: string, digest: string} */
    public function toStored(): array
    {
        return ['version' => self::VERSION, 'project_id' => $this->projectId, 'through' => $this->through, 'digest' => $this->digest];
    }

    public function matches(self $current): bool
    {
        return $this->projectId === $current->projectId
            && $this->through === $current->through
            && hash_equals($this->digest, $current->digest);
    }
}
