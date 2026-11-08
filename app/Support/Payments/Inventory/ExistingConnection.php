<?php

namespace App\Support\Payments\Inventory;

/** A payment connection that already exists at the processor, and what the migration does about it. */
final readonly class ExistingConnection
{
    public const string BLOCKS_OWNER = 'blocks_owner';

    public const string REVOKED_EARLIER = 'revoked_earlier';

    public const string ENVIRONMENT_MIGRATED = 'environment_migrated';

    public const string UNAFFECTED = 'unaffected';

    public function __construct(
        public WorkspaceRef $workspace,
        public string $origin,
        public bool $active,
        public string $effect,
    ) {}

    public function sentence(): string
    {
        $state = ($this->active ? 'an active ' : 'a revoked ').$this->origin.' connection';

        return match ($this->effect) {
            self::BLOCKS_OWNER => $this->workspace->name.' already has '.$state.'. The command skips it and never replaces it.',
            self::REVOKED_EARLIER => $this->workspace->name.' has '.$state.'. It does not block the migration, which adds a new connection.',
            self::ENVIRONMENT_MIGRATED => $this->workspace->name.' holds '.$state.' made from the environment credentials, so the environment is already retired for this processor.',
            default => $this->workspace->name.' has '.$state.'. It is untouched.',
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['workspace' => $this->workspace->toArray(), 'origin' => $this->origin, 'active' => $this->active, 'effect' => $this->effect];
    }
}
