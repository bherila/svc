<?php

namespace App\Support\Payments\Inventory;

/**
 * Which Square location the migration would use. A preview never calls Square,
 * so the only evidence about other locations is the workspace's local mirror.
 */
final readonly class SquareLocationFacts
{
    public const string RULE = 'The first location Square returns for the access token when --apply runs. The migration has no location setting.';

    /** @param list<string> $mirroredNames */
    public function __construct(public int $mirroredCount, public array $mirroredNames) {}

    /** True or false from the mirror; null when the mirror has never synced, so nobody knows. */
    public function otherLocationsExist(): ?bool
    {
        return $this->mirroredCount === 0 ? null : $this->mirroredCount > 1;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'chosen' => 'not checked',
            'rule' => self::RULE,
            'mirrored_locations' => $this->mirroredCount,
            'mirrored_location_names' => $this->mirroredNames,
            'other_locations_exist' => $this->otherLocationsExist(),
        ];
    }
}
