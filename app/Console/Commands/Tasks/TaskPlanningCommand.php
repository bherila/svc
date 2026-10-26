<?php

namespace App\Console\Commands\Tasks;

use App\Console\Commands\Concerns\FindsUserByPublicId;
use App\Console\Commands\MutationCommand;
use App\Http\Requests\TaskPlanningInput;
use App\Services\AgentApi\AgentTaskPlanningMutationAction;
use App\Services\Tasks\Planning\TaskPlanningDetailsGate;
use App\Services\Tasks\Planning\TaskPlanningTransport;
use App\Services\Tasks\TaskMutationOrigin;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use JsonException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Trusted local adapter: mutations preview the common receipt action by default. */
final class TaskPlanningCommand extends MutationCommand
{
    use FindsUserByPublicId;

    protected $signature = 'svc:tasks:planning
        {operation : get; labels.list/get/create/update/archive/restore/options/replace; dependencies.get/options/replace; contacts.get/options/replace}
        {workspace : Workspace public UUID}
        {target? : Task or label public UUID, as required by the operation}
        {--actor= : Current internal account public UUID}
        {--project= : Project public UUID for labels.options}
        {--q= : Search text for catalog/options}
        {--status= : active, archived or all for labels.list}
        {--limit=25 : Page size, between 1 and 25}
        {--cursor= : Opaque continuation cursor}
        {--input= : Private JSON mutation request file, or - for stdin}
        {--key= : Required retry key for a mutation}';

    protected $description = 'Read task planning and maintain labels, prerequisites and contact responsibility';

    public function isHidden(): bool
    {
        return config('task_planning_details.enabled') !== true;
    }

    public function handle(TaskPlanningTransport $transport, AgentTaskPlanningMutationAction $writes): int
    {
        try {
            TaskPlanningDetailsGate::assertEnabled();
            $operation = (string) $this->argument('operation');
            abort_unless(in_array($operation, [...TaskPlanningInput::READS, ...TaskPlanningInput::WRITES], true), 404);
            $writing = in_array($operation, TaskPlanningInput::WRITES, true);
            $targetName = TaskPlanningInput::targetName($operation);
            $target = $this->argument('target');
            $project = $this->option('project');
            if ($operation === 'labels.options') {
                abort_unless($target === null && is_string($project), 422, 'Use --project, without a target, for label options.');
                $target = $project;
            } else {
                abort_unless($project === null, 422, 'The project option is only valid for label options.');
            }
            $identity = validator(['target' => $target, 'key' => $this->option('key')], [
                'target' => [$targetName === null ? 'prohibited' : 'required', 'nullable', 'uuid'],
                'key' => [$writing ? 'required' : 'prohibited', 'nullable', 'string', 'max:255'],
            ])->validate();
            $query = [];
            foreach (['q', 'status', 'cursor', 'limit'] as $option) {
                if ($this->input->hasParameterOption('--'.$option, true)) {
                    $query[$option] = $this->option($option);
                }
            }
            if ($writing) {
                abort_unless($query === [], 422, 'Search and page options do not apply to mutations.');
            } else {
                abort_unless($this->option('input') === null && ! $this->applying(), 422, 'Read operations do not accept mutation input or --apply.');
                $query = TaskPlanningInput::query($operation, $query);
            }
            $workspace = $this->workspaceByPublicId((string) $this->argument('workspace'));
            $actor = $this->userByPublicId((string) $this->option('actor'));
            if ($workspace === null || is_int($actor)) {
                $this->error('Choose an available workspace and current internal account.');

                return self::INVALID;
            }
            if ($writing) {
                $body = $this->payload();
                $id = $this->preview(fn (): string => $writes->executeInternal($actor, $workspace, 'svc-cli-task-planning',
                    $identity['key'], $operation, $identity['target'] ?? null, $body));
                $result = ['data' => ['id' => $id], 'dry_run' => ! $this->applying()];
            } else {
                $result = $transport->read($actor, $workspace, TaskMutationOrigin::Internal, $operation, $identity['target'] ?? null, $query);
            }
            $this->json($result);

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->error($field.': '.implode(' ', $messages));
            }

            return self::INVALID;
        } catch (ModelNotFoundException|HttpExceptionInterface|JsonException|\DomainException) {
            $this->error('The planning operation is unavailable, invalid or stale. Read it again before saving.');

            return self::FAILURE;
        }
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        $path = $this->option('input');
        abort_unless(is_string($path) && $path !== '' && ($path === '-' || (is_file($path) && is_readable($path))), 422);
        $bytes = file_get_contents($path === '-' ? 'php://stdin' : $path, length: 1048577);
        abort_unless(is_string($bytes) && strlen($bytes) <= 1048576 && str_starts_with(ltrim($bytes), '{'), 422);
        $body = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        abort_unless(is_array($body), 422);

        return $body;
    }
}
