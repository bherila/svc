<?php

namespace App\Support\ClientHome;

use App\Models\ClientTask;
use App\Support\AgentApi\Presenters\AgentTaskPresenter;

/** One open task, as a row rather than a card. */
final class TaskRow
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly ?string $project,
        public readonly string $status,
        public readonly ?string $filesHref = null,
    ) {}

    public static function fromTask(ClientTask $task, ?string $project, ?string $filesHref = null): self
    {
        $facts = AgentTaskPresenter::identity($task);

        return new self(id: $facts['id'], title: $facts['title'], project: $project, status: $task->status, filesHref: $filesHref);
    }

    /**
     * @return array{id: string, title: string, project: string|null, status: string, files_href?:string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'project' => $this->project,
            'status' => $this->status,
        ] + ($this->filesHref === null ? [] : ['files_href' => $this->filesHref]);
    }
}
