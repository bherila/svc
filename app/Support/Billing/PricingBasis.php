<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Services\Billing\PricingBasisRecorder;

/**
 * What a cadence draft was priced from (#718): its agreement's project scope,
 * the last day its ledger read, and a digest of every input that ledger reads.
 * Stored on the draft at generation and compared at issue with the same
 * inputs read under row locks; see {@see PricingBasisRecorder}.
 */
final readonly class PricingBasis
{
    private const int VERSION = 1;

    /**
     * @param  int  $upTo  the highest time entry id the draft was priced from:
     *                     work logged after it is later work, which a later
     *                     invoice bills, and does not make this draft wrong
     * @param  int  $invoicesUpTo  the highest invoice id when it was priced: an invoice
     *                             created since is later than this draft
     */
    private function __construct(
        public ?int $projectId,
        public string $through,
        public int $upTo,
        public int $invoicesUpTo,
        public string $digest,
    ) {}

    /** @param  array<string, list<list<scalar|null>>>  $inputs  each input's normalised rows, by name */
    public static function of(?int $projectId, string $through, int $upTo, int $invoicesUpTo, array $inputs): self
    {
        ksort($inputs);

        return new self($projectId, $through, $upTo, $invoicesUpTo, hash('sha256', (string) json_encode($inputs, JSON_THROW_ON_ERROR)));
    }

    /** @param  array<mixed>|null  $stored */
    public static function fromStored(?array $stored): ?self
    {
        if ($stored === null || ($stored['version'] ?? null) !== self::VERSION) {
            return null;
        }
        $projectId = $stored['project_id'] ?? null;
        $through = $stored['through'] ?? null;
        $upTo = $stored['up_to'] ?? null;
        $invoicesUpTo = $stored['invoices_up_to'] ?? null;
        $digest = $stored['digest'] ?? null;
        if (! ($projectId === null || is_int($projectId)) || ! is_string($through) || ! is_int($upTo) || ! is_int($invoicesUpTo) || ! is_string($digest)) {
            return null;
        }

        return new self($projectId, $through, $upTo, $invoicesUpTo, $digest);
    }

    /** @return array{version: int, project_id: int|null, through: string, up_to: int, invoices_up_to: int, digest: string} */
    public function toStored(): array
    {
        return [
            'version' => self::VERSION, 'project_id' => $this->projectId, 'through' => $this->through,
            'up_to' => $this->upTo, 'invoices_up_to' => $this->invoicesUpTo, 'digest' => $this->digest,
        ];
    }

    public function matches(self $current): bool
    {
        return $this->projectId === $current->projectId
            && $this->through === $current->through
            && $this->upTo === $current->upTo
            && $this->invoicesUpTo === $current->invoicesUpTo
            && hash_equals($this->digest, $current->digest);
    }
}
