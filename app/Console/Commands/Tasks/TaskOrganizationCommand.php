<?php

namespace App\Console\Commands\Tasks;

use App\Console\Commands\Concerns\FindsUserByPublicId;
use App\Console\Commands\MutationCommand;
use App\Http\Requests\TaskOrganizationInput;
use App\Services\AgentApi\AgentTaskOrganizationMutationAction;
use App\Services\Tasks\Organization\TaskOrganizationAction;
use App\Services\Tasks\Organization\TaskOrganizationGate;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use JsonException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Local native adapter; mutations preview within a fully rolled-back transaction. */
final class TaskOrganizationCommand extends MutationCommand
{
    use FindsUserByPublicId;

    protected $signature = 'svc:tasks:organization
        {operation : get, lists.create/update/archive/restore/reorder, or tasks.create/move/reorder}
        {workspace : Workspace public UUID}
        {project : Project public UUID}
        {--actor= : Current internal account public UUID}
        {--container : Include the complete bounded task sibling container}
        {--parent= : Parent task public UUID for get --container}
        {--list= : Root list public UUID for get --container}
        {--input= : JSON mutation request file, or - for standard input}
        {--key= : Retry key for a mutation}';

    protected $description = 'Maintain task lists, subtasks and ordering, previewing mutations by default';

    public function isHidden(): bool
    {
        return config('task_organization.enabled') !== true;
    }

    public function handle(TaskOrganizationAction $organization, AgentTaskOrganizationMutationAction $writes): int
    {
        try {
            TaskOrganizationGate::assertEnabled();
            $workspace = $this->workspaceByPublicId((string) $this->argument('workspace'));
            $actor = $this->userByPublicId((string) $this->option('actor'));
            if ($workspace === null || is_int($actor)) {
                $this->error('Choose an available workspace and current internal account.');

                return self::INVALID;
            }
            $operation = (string) $this->argument('operation');
            $options = validator(['project' => $this->argument('project'), 'parent' => $this->option('parent'), 'list' => $this->option('list'), 'key' => $this->option('key')],
                ['project' => ['required', 'uuid'], 'parent' => ['nullable', 'uuid'], 'list' => ['nullable', 'uuid'],
                    'key' => [$operation === 'get' ? 'nullable' : 'required', 'string', 'max:255']])->validate();
            if ($operation === 'get') {
                $container = TaskOrganizationInput::container(['container' => $this->option('container'), 'parent_id' => $options['parent'], 'list_id' => $options['list']]);
                $result = ['data' => $organization->snapshot($actor, $workspace, $options['project'], $container)->data];
            } else {
                $input = $this->option('input');
                if (! is_string($input) || $input === '' || ($input !== '-' && (! is_file($input) || ! is_readable($input)))) {
                    $this->error('Give --input with a readable JSON request file, or - for standard input.');

                    return self::INVALID;
                }
                $bytes = file_get_contents($input === '-' ? 'php://stdin' : $input, length: 1048577);
                if (! is_string($bytes) || strlen($bytes) > 1048576) {
                    $this->error('The JSON request must be at most 1 MiB.');

                    return self::INVALID;
                }
                $payload = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($payload) || ! str_starts_with(ltrim($bytes), '{')) {
                    $this->error('The JSON request must be an object.');

                    return self::INVALID;
                }
                $ids = $this->preview(fn (): array => $writes->executeInternal($actor, $workspace, 'svc-cli-task-organization', $options['key'], $options['project'], $operation, $payload));
                $result = ['data' => ['affected_ids' => $ids], 'dry_run' => ! $this->applying()];
            }
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->error($field.': '.implode(' ', $messages));
            }

            return self::INVALID;
        } catch (ModelNotFoundException|HttpExceptionInterface|JsonException|\DomainException) {
            $this->error('The organization operation is unavailable or stale. Read it again before saving.');

            return self::FAILURE;
        }
        $this->json($result);

        return self::SUCCESS;
    }
}
