<?php

namespace App\Console\Commands;

use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Engagement\EngagementException;
use App\Services\Engagement\TimeEntryWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class LogTimeEntryCommand extends Command
{
    protected $signature = 'engagement:log-time
        {workspace : Workspace public UUID or slug}
        {project : Project public UUID or slugified name}
        {minutes : Integer duration in minutes}
        {description : Work description}
        {--user= : Worker user ID or email}
        {--worked-on= : Work date in YYYY-MM-DD format (UTC today by default)}
        {--billable : Mark this entry billable}
        {--deferred : Mark this entry deferred}
        {--defer : Alias for --deferred}
        {--rate= : Billing rate in integer currency minor units}
        {--currency= : ISO 4217 currency code}
        {--format=text : Output text or json}';

    protected $aliases = ['svc:engagement:log-time', 'svc:log-time'];

    protected $description = 'Log synthetic-safe client engagement time in integer minutes';

    public function handle(TimeEntryWorkflow $workflow): int
    {
        $format = (string) $this->option('format');

        if (! in_array($format, ['text', 'json'], true)) {
            $this->error('The --format option must be text or json.');

            return self::INVALID;
        }

        try {
            $workspace = $this->resolveWorkspace($this->stringValue($this->argument('workspace'), 'workspace'));
            $project = $this->resolveProject($workspace, $this->stringValue($this->argument('project'), 'project'));
            $worker = $this->resolveWorker($workspace, $this->stringValue($this->option('user'), '--user'));
            $minutes = $this->integerArgument('minutes', 1, 1440);
            $workedOn = $this->workedOn();
            $description = trim($this->stringValue($this->argument('description'), 'description'));
            $rate = $this->optionalInteger('rate', 0);
            $currency = $this->currency();

            if ($description === '') {
                throw new EngagementException('The description must not be empty.');
            }

            if ($rate !== null && $currency === null) {
                throw new EngagementException('Currency is required when a billing rate is supplied.');
            }

            $entry = $workflow->create($workspace, $project, $worker, [
                'worked_on' => $workedOn,
                'minutes' => $minutes,
                'description' => $description,
                'is_billable' => (bool) $this->option('billable'),
                'is_deferred' => (bool) ($this->option('deferred') || $this->option('defer')),
                'billing_rate_amount' => $rate,
                'currency' => $currency,
            ]);

            $payload = [
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
                $this->line((string) json_encode($payload, JSON_THROW_ON_ERROR));
            } else {
                $this->info(sprintf('Logged %d minutes to %s (%s).', $minutes, $project->name, $entry->public_id));
            }

            return self::SUCCESS;
        } catch (EngagementException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }
    }

    private function resolveWorkspace(string $value): Workspace
    {
        $matches = Workspace::query()
            ->where(static fn ($query) => $query->where('public_id', $value)->orWhere('slug', $value))
            ->get();

        if ($matches->count() !== 1) {
            throw new EngagementException($matches->isEmpty() ? 'Workspace not found.' : 'Workspace lookup is ambiguous.');
        }

        return $matches->sole();
    }

    private function resolveProject(Workspace $workspace, string $value): ClientProject
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
            throw new EngagementException($projects->isEmpty() ? 'Project not found.' : 'Project lookup is ambiguous.');
        }

        return $projects->sole();
    }

    private function resolveWorker(Workspace $workspace, string $value): User
    {
        if ($value === '') {
            throw new EngagementException('The --user option is required.');
        }

        $query = ctype_digit($value)
            ? User::query()->whereKey((int) $value)
            : User::query()->whereRaw('lower(email) = ?', [strtolower($value)]);
        $worker = $query->first();

        if ($worker === null) {
            throw new EngagementException('Worker not found.');
        }

        if (! $workspace->memberships()->where('user_id', $worker->id)->exists()) {
            throw new EngagementException('The worker is not a member of this workspace.');
        }

        return $worker;
    }

    private function workedOn(): CarbonImmutable
    {
        $value = $this->stringValue($this->option('worked-on') ?: now('UTC')->toDateString(), '--worked-on');
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');

        if ($date === null || $date->format('Y-m-d') !== $value) {
            throw new EngagementException('The --worked-on option must be a valid YYYY-MM-DD date.');
        }

        return $date;
    }

    private function integerArgument(string $name, int $minimum, int $maximum): int
    {
        $value = $this->stringValue($this->argument($name), $name);

        if (! preg_match('/\A\d+\z/', $value)) {
            throw new EngagementException("The {$name} value must be an integer.");
        }

        $integer = (int) $value;

        if ($integer < $minimum || $integer > $maximum) {
            throw new EngagementException("The {$name} value must be between {$minimum} and {$maximum}.");
        }

        return $integer;
    }

    private function optionalInteger(string $name, int $minimum): ?int
    {
        $value = $this->stringValue($this->option($name) ?? '', "--{$name}");

        if ($value === '') {
            return null;
        }

        if (! preg_match('/\A\d+\z/', $value) || (int) $value < $minimum) {
            throw new EngagementException("The --{$name} value must be a non-negative integer.");
        }

        return (int) $value;
    }

    private function currency(): ?string
    {
        $value = strtoupper(trim($this->stringValue($this->option('currency') ?? '', '--currency')));

        if ($value === '') {
            return null;
        }

        if (! preg_match('/\A[A-Z]{3}\z/', $value)) {
            throw new EngagementException('The --currency option must be an ISO 4217 three-letter code.');
        }

        return $value;
    }

    private function stringValue(mixed $value, string $name): string
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        throw new EngagementException("The {$name} value must be a scalar string.");
    }
}
