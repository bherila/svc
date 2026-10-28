<?php

namespace App\Support\ClientHome;

use App\Models\ClientTimeEntry;
use App\Support\AgentApi\Presenters\AgentTimeEntryPresenter;

/**
 * One line of recent work.
 *
 * The description is nullable because the two adapters mean different things by
 * it: an operator reads the internal note, and a client reads only the
 * client-visible one - never a fallback to the internal text, which may say
 * anything. A row cleared for nobody arrives here with null rather than with
 * the operator's words.
 */
final class TimeEntryRow
{
    public function __construct(
        public readonly string $id,
        public readonly string $workedOn,
        public readonly ?string $project,
        public readonly ?string $description,
        public readonly int $minutes,
    ) {}

    public static function fromEntry(ClientTimeEntry $entry, ?string $project, bool $useClientDescription): self
    {
        $facts = AgentTimeEntryPresenter::summary($entry, useClientDescription: $useClientDescription);

        return new self(id: $facts['id'], workedOn: $facts['worked_on'], project: $project,
            description: $facts['description'], minutes: $facts['minutes']);
    }

    /**
     * @return array{id: string, worked_on: string, project: string|null, description: string|null, minutes: int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'worked_on' => $this->workedOn,
            'project' => $this->project,
            'description' => $this->description,
            'minutes' => $this->minutes,
        ];
    }
}
