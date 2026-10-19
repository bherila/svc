<?php

namespace App\Console\Commands\Engagement;

use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\TimeEntryMutationService;
use App\Support\Engagement\TimeEntryCreationRules;
use App\Support\WorkspaceClock;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The console twin of `time_entries.log`.
 *
 * It validates through the same {@see TimeEntryCreationRules} and creates
 * through the same {@see TimeEntryMutationService::create()} as REST, MCP and
 * the time sheet (#460), so the worker named by `--user` needs the project
 * role that may log time there, only someone who may approve the time (a
 * project owner or manager, or a workspace owner or admin) may name the rate
 * it bills at, client-facing text makes the entry client-visible unless
 * told otherwise, and "today" is the workspace's day rather than UTC's.
 *
 * Exit codes: 0 logged; 2 (`INVALID`) the input was refused, as the API's
 * 422; 1 (`FAILURE`) the workspace, project, task or worker was not found or
 * may not log this time, as the API's 404 and 403.
 */
class LogTimeEntryCommand extends Command
{
    protected $signature = 'svc:engagement:log-time
        {workspace : Workspace public UUID or slug}
        {project : Project public UUID or slugified name}
        {minutes : Integer duration in minutes}
        {description : Work description}
        {--user= : Worker user ID or email}
        {--worked-on= : Work date in YYYY-MM-DD format (default: today on the workspace calendar)}
        {--task= : Task public UUID on the same project}
        {--billable : Mark this entry billable (the default, as on REST)}
        {--no-billable : Mark this entry not billable}
        {--deferred : Mark this entry deferred}
        {--defer : Alias for --deferred}
        {--client-description= : Client-facing description; makes the entry client-visible unless --internal is given}
        {--internal : Keep the entry hidden from the client even with a client-facing description}
        {--rate= : Billing rate in integer currency minor units (only those who may approve the time: project owners and managers, workspace owners and admins)}
        {--currency= : ISO 4217 currency code (default: the workspace currency)}
        {--format=text : Output text or json}';

    protected $aliases = ['engagement:log-time', 'svc:log-time'];

    protected $description = 'Log client engagement time in integer minutes, as time_entries.log does';

    public function handle(TimeEntryMutationService $time, WorkspaceClock $clock): int
    {
        $format = (string) $this->option('format');

        if (! in_array($format, ['text', 'json'], true)) {
            $this->error('The --format option must be text or json.');

            return self::INVALID;
        }
        if ($this->option('billable') && $this->option('no-billable')) {
            $this->error('Give --billable or --no-billable, not both.');

            return self::INVALID;
        }

        $workspace = $this->resolveWorkspace((string) $this->argument('workspace'));
        if (is_string($workspace)) {
            $this->error($workspace);

            return self::FAILURE;
        }
        $project = $this->resolveProject($workspace, (string) $this->argument('project'));
        if (is_string($project)) {
            $this->error($project);

            return self::FAILURE;
        }
        $worker = $this->resolveWorker($workspace, (string) $this->option('user'));
        if (is_string($worker)) {
            $this->error($worker);

            return self::FAILURE;
        }

        $payload = $this->payload($workspace, $project, $clock);

        try {
            $data = Validator::make($payload, TimeEntryCreationRules::entry($workspace->timezone))->validate();
            $entry = $time->create($workspace, $project, $worker, $data);
        } catch (ValidationException $invalid) {
            foreach ($invalid->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::INVALID;
        } catch (DomainException $refused) {
            $this->error($refused->getMessage());

            return self::INVALID;
        } catch (ModelNotFoundException) {
            $this->error('Task not found in the requested workspace.');

            return self::FAILURE;
        } catch (HttpExceptionInterface $refused) {
            $this->error($refused->getStatusCode() === 403
                ? 'The worker may not log time on this project.'
                : 'Project not found in the requested workspace.');

            return self::FAILURE;
        }

        $result = [
            'id' => $entry->public_id,
            'workspace_id' => $workspace->public_id,
            'client_company_id' => $entry->clientCompany->public_id,
            'project_id' => $project->public_id,
            'worked_on' => $entry->worked_on->toDateString(),
            'minutes' => $entry->minutes,
            'description' => $entry->description,
            'is_billable' => $entry->is_billable,
            'is_deferred' => $entry->is_deferred,
            'billing_rate_amount' => $entry->billing_rate_amount,
            'currency' => $entry->currency,
            'status' => $entry->status,
        ];

        if ($format === 'json') {
            $this->line((string) json_encode($result, JSON_THROW_ON_ERROR));
        } else {
            $this->info(sprintf('Logged %d minutes to %s (%s).', $entry->minutes, $project->name, $entry->public_id));
        }

        return self::SUCCESS;
    }

    /**
     * The command's arguments as the API's entry payload.
     *
     * Values are passed as given, so a malformed number or date is refused by
     * the shared rules with the API's own message. An option not given is not
     * sent, so the shared defaults apply as they do to an omitted field.
     *
     * @return array<string, mixed>
     */
    private function payload(Workspace $workspace, ClientProject $project, WorkspaceClock $clock): array
    {
        $payload = [
            'project_id' => $project->public_id,
            'worked_on' => $this->text('worked-on') ?? $clock->today($workspace)->toDateString(),
            'minutes' => $this->argument('minutes'),
            // The API's request pipeline trims every string; a console argument has no pipeline.
            'description' => trim((string) $this->argument('description')),
            'is_deferred' => (bool) ($this->option('deferred') || $this->option('defer')),
        ];
        $optional = [
            'task_id' => $this->text('task'),
            'client_visible_description' => $this->text('client-description'),
            'billing_rate_amount' => $this->text('rate'),
            'currency' => $this->text('currency'),
        ];
        foreach ($optional as $field => $value) {
            if ($value !== null) {
                $payload[$field] = $value;
            }
        }
        // Omitted unless stated, so the shared default (billable) applies as
        // it does to a REST entry without the field.
        if ($this->option('billable')) {
            $payload['is_billable'] = true;
        }
        if ($this->option('no-billable')) {
            $payload['is_billable'] = false;
        }
        if ($this->option('internal')) {
            $payload['is_visible_to_client'] = false;
        }
        // A console argument is always a string. A whole number is passed as
        // the integer the API would have received; anything else is passed
        // through for the shared rules to refuse.
        foreach (['minutes', 'billing_rate_amount'] as $field) {
            if (is_string($payload[$field] ?? null) && preg_match('/\A\d{1,9}\z/', $payload[$field]) === 1) {
                $payload[$field] = (int) $payload[$field];
            }
        }

        return $payload;
    }

    /** An option given empty means not given. */
    private function text(string $option): ?string
    {
        $value = $this->option($option);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function resolveWorkspace(string $value): Workspace|string
    {
        $matches = Workspace::query()
            ->where(static fn ($query) => $query->where('public_id', $value)->orWhere('slug', $value))
            ->get();

        if ($matches->count() !== 1) {
            return $matches->isEmpty() ? 'Workspace not found.' : 'Workspace lookup is ambiguous.';
        }

        return $matches->sole();
    }

    private function resolveProject(Workspace $workspace, string $value): ClientProject|string
    {
        $projects = ClientProject::query()
            ->where('workspace_id', $workspace->id)
            ->where(static fn ($query) => $query->where('public_id', $value)->orWhere('name', $value))
            ->get();

        if ($projects->isEmpty()) {
            $projects = ClientProject::query()->where('workspace_id', $workspace->id)->get()
                ->filter(static fn (ClientProject $project): bool => Str::slug($project->name) === Str::slug($value));
        }

        if ($projects->count() !== 1) {
            return $projects->isEmpty() ? 'Project not found.' : 'Project lookup is ambiguous.';
        }

        return $projects->sole();
    }

    private function resolveWorker(Workspace $workspace, string $value): User|string
    {
        if ($value === '') {
            return 'The --user option is required.';
        }

        $query = ctype_digit($value)
            ? User::query()->whereKey((int) $value)
            : User::query()->whereRaw('lower(email) = ?', [strtolower($value)]);
        $worker = $query->first();

        if ($worker === null) {
            return 'Worker not found.';
        }

        if (! $workspace->memberships()->where('user_id', $worker->id)->exists()) {
            return 'The worker is not a member of this workspace.';
        }

        return $worker;
    }
}
