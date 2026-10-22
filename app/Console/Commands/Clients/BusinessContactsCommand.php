<?php

namespace App\Console\Commands\Clients;

use App\Console\Commands\Concerns\FindsUserByPublicId;
use App\Console\Commands\MutationCommand;
use App\Services\AgentApi\AgentClientContactMutationAction;
use App\Services\AgentApi\AgentClientContactReadService;
use App\Services\Clients\ContactMutationOrigin;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use JsonException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Local adapter: the same scoped operations and versions, preview by default. */
final class BusinessContactsCommand extends MutationCommand
{
    use FindsUserByPublicId;

    protected $signature = 'svc:clients:contacts
        {operation : list, get, create, update, archive or restore}
        {workspace : Workspace public UUID}
        {company : Client company public UUID}
        {--actor= : Current workspace manager public UUID}
        {--contact= : Business contact public UUID for get/update/archive/restore}
        {--input= : JSON request file, or - to read standard input}
        {--key= : Retry key for a mutation}
        {--status= : active or archived, for listing}
        {--limit=25 : Listing page size, at most 100}
        {--cursor= : Listing continuation cursor}';

    protected $description = 'Read and maintain business contacts without changing portal access';

    public function handle(AgentClientContactReadService $reads, AgentClientContactMutationAction $writes): int
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
        $company = (string) $this->argument('company');
        $contact = $this->option('contact');
        try {
            if ($operation === 'list') {
                $filters = validator(['status' => $this->option('status'), 'limit' => $this->option('limit'), 'cursor' => $this->option('cursor')],
                    ['status' => ['nullable', 'in:active,archived'], 'limit' => ['required', 'integer', 'min:1', 'max:100'], 'cursor' => ['nullable', 'string', 'max:2048']])->validate();
                $result = $reads->listing($actor, $workspace, $company, $filters['status'], (int) $filters['limit'], $filters['cursor']);
            } elseif ($operation === 'get') {
                $result = ['data' => $reads->get($actor, $workspace, $company, (string) $contact)];
            } else {
                $input = $this->option('input');
                if (! is_string($input) || $input === '' || ! in_array($operation, ['create', 'update', 'archive', 'restore'], true)) {
                    $this->error('Give a supported operation and --input with its JSON request.');

                    return self::INVALID;
                }
                if ($input !== '-' && (! is_file($input) || ! is_readable($input))) {
                    $this->error('The JSON request file is not readable.');

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
                $result = $this->preview(function () use ($writes, $reads, $actor, $workspace, $company, $operation, $contact, $payload): array {
                    $id = $writes->perform($actor, $workspace, $company, $operation, is_string($contact) ? $contact : null, $payload, 'svc-cli-business-contacts', (string) $this->option('key'), ContactMutationOrigin::Internal);

                    return ['data' => $reads->get($actor, $workspace, $company, $id)];
                });
                $result['dry_run'] = ! $this->applying();
            }
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->error($field.': '.implode(' ', $messages));
            }

            return self::INVALID;
        } catch (ModelNotFoundException|HttpException|JsonException $exception) {
            $this->error($exception instanceof JsonException ? 'The request is not valid JSON.' : 'The requested operation or record is not available to this actor.');

            return self::FAILURE;
        }
        if ($this->option('format') === 'json') {
            $this->json($result);
        } elseif ($operation === 'list') {
            $this->table(['ID', 'Name', 'Email', 'Archived'], array_map(static fn (array $contact): array => [
                $contact['id'], trim(($contact['first_name'] ?? '').' '.($contact['last_name'] ?? '')),
                $contact['email'] ?? '', $contact['is_archived'] ? 'yes' : 'no',
            ], $result['data']));
            if ($result['meta']['next_cursor'] !== null) {
                $this->line('Next cursor: '.$result['meta']['next_cursor']);
            }
        } elseif (is_array($result['data'])) {
            $this->table(['Field', 'Value'], collect($result['data'])->map(static fn (mixed $value, int|string $field): array => [
                $field, is_bool($value) ? ($value ? 'yes' : 'no') : ($value ?? ''),
            ])->values()->all());
            if (($result['dry_run'] ?? false) === true) {
                $this->info('Preview only. Use --apply to save these changes.');
            }
        }

        return self::SUCCESS;
    }
}
