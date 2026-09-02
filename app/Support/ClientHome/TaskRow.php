<?php

namespace App\Support\ClientHome;

/** One open task, as a row rather than a card. */
final class TaskRow
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly ?string $project,
        public readonly string $status,
    ) {}

    /**
     * @return array{id: string, title: string, project: string|null, status: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'project' => $this->project,
            'status' => $this->status,
        ];
    }
}
