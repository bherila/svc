<?php

namespace App\Console\Commands\Tasks;

use App\Console\Commands\Concerns\FindsUserByPublicId;
use App\Console\Commands\MutationCommand;
use App\Services\AgentApi\AgentReadService;
use App\Services\AgentApi\AgentTaskMutationAction;
use App\Services\Tasks\TaskInputRules;
use App\Services\Tasks\TaskMutationOrigin;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use JsonException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Local task adapter with the same scoped lifecycle and retry receipts. */
final class TasksCommand extends MutationCommand
{
    use FindsUserByPublicId;

    protected $signature = 'svc:tasks
        {operation : list, get, create or update}
        {workspace : Workspace public UUID}
        {--actor= : Current internal account public UUID}
        {--project= : Project public UUID, required for create and optional for list}
        {--task= : Task public UUID for get/update}
        {--input= : JSON request file, or - for standard input}
        {--key= : Retry key for a mutation}
        {--limit=25 : List page size, at most 100}
        {--cursor= : Listing continuation cursor}';

    protected $description = 'Read and maintain tasks, previewing mutations by default';

    public function handle(AgentReadService $reads, AgentTaskMutationAction $writes): int
    {
        $workspace = $this->workspaceByPublicId((string) $this->argument('workspace'));
        $actor = $this->userByPublicId((string) $this->option('actor'));
        if ($workspace === null || is_int($actor)) {
            if ($workspace === null) {
                $this->error('No workspace matches that public UUID.');
            }

            return self::INVALID;
        }
        $operation = (string) $this->argument('operation');
        try {
            $options = validator(['project' => $this->option('project'), 'task' => $this->option('task'),
                'key' => $this->option('key'), 'limit' => $this->option('limit'), 'cursor' => $this->option('cursor')], [
                    'project' => [$operation === 'create' ? 'required' : 'nullable', 'uuid'],
                    'task' => [in_array($operation, ['get', 'update'], true) ? 'required' : 'nullable', 'uuid'],
                    'key' => [in_array($operation, ['create', 'update'], true) ? 'required' : 'nullable', 'string', 'max:255'],
                    'limit' => ['required', 'integer', 'min:1', 'max:100'], 'cursor' => ['nullable', 'string', 'max:2048'],
                ])->validate();
            if ($operation === 'list') {
                $result = $reads->tasks($actor, $workspace, $options['project'], (int) $options['limit'], $options['cursor'], TaskMutationOrigin::Internal);
            } elseif ($operation === 'get') {
                $result = ['data' => $reads->task($actor, $workspace, $options['task'], TaskMutationOrigin::Internal)];
            } elseif (in_array($operation, ['create', 'update'], true)) {
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
                $rules = $operation === 'create' ? TaskInputRules::create() : ['expected_version' => ['required', 'string', 'size:64']] + TaskInputRules::patch();
                $data = validator($payload, $rules)->validate();
                // Keep the pre-completion warning in a preview even when its
                // simulated response already describes the completed parent.
                $completing = config('task_organization.enabled') === true && $operation === 'update' && ($data['status'] ?? null) === 'completed';
                $advisory = $completing ? ($reads->task($actor, $workspace, $options['task'], TaskMutationOrigin::Internal)['completion_advisory'] ?? null) : null;
                $result = $this->preview(function () use ($writes, $reads, $actor, $workspace, $operation, $options, $data, $completing, $advisory): array {
                    $task = $operation === 'create'
                        ? $writes->createInternal($actor, $workspace, 'svc-cli-tasks', $options['key'], $options['project'], $data)
                        : $writes->updateInternal($actor, $workspace, 'svc-cli-tasks', $options['key'], $options['task'], $data);

                    $presented = $reads->task($actor, $workspace, $task->public_id, TaskMutationOrigin::Internal);
                    if ($completing) {
                        $presented['completion_advisory'] = $advisory;
                    }

                    return ['data' => $presented];
                });
                $result['dry_run'] = ! $this->applying();
            } else {
                $this->error('Use list, get, create or update.');

                return self::INVALID;
            }
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->error($field.': '.implode(' ', $messages));
            }

            return self::INVALID;
        } catch (ModelNotFoundException|HttpException|JsonException $exception) {
            $this->error($exception instanceof JsonException ? 'The request is not valid JSON.' : 'The task operation is unavailable or its version is stale. Read it again before saving.');

            return self::FAILURE;
        }
        if ($this->option('format') === 'json') {
            $this->json($result);
        } else {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            if (($result['dry_run'] ?? false) === true) {
                $this->info('Preview only. Use --apply to save these changes.');
            }
        }

        return self::SUCCESS;
    }
}
