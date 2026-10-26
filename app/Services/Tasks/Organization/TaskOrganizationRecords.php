<?php

namespace App\Services\Tasks\Organization;

use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTaskGraphState;
use App\Models\ClientTaskList;
use Illuminate\Database\Eloquent\Collection;

/** Persistence handle held inside one scoped repository transaction, not public facts. */
final class TaskOrganizationRecords
{
    /** @param Collection<int, ClientTask> $tasks
     * @param Collection<int, ClientTaskList> $lists */
    public function __construct(public readonly ClientProject $project, public readonly ClientTaskGraphState $state, public readonly Collection $tasks, public readonly Collection $lists) {}
}
