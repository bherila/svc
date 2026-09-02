<?php

namespace App\Support\Navigation;

/** One company the switcher may offer, with the routes this viewer may use. */
final class ClientNavigationOption
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ClientModuleDestinations $destinations,
    ) {}

    /**
     * @return array{id: string, name: string, destinations: array{home: string, invoices: string|null, time: string|null, expenses: string|null, tasks: string|null}}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'destinations' => $this->destinations->toArray(),
        ];
    }
}
