<?php

namespace App\Console\Commands\Collaboration;

use App\Console\Commands\Concerns\FindsUserByPublicId;
use App\Console\Commands\MutationCommand;
use App\Services\Collaboration\CollaborationAudience;
use App\Services\Collaboration\CollaborationTransportService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Private JSON input keeps collaboration text out of process arguments and shell history. */
final class CollaborationCommand extends MutationCommand
{
    use FindsUserByPublicId;

    protected $signature = 'svc:collaboration {operation : topics.list|get|create|update|delete or comments.list|create|update|delete}
        {workspace : Workspace public UUID} {client : Client company public UUID}
        {--actor= : Acting user public UUID} {--input=- : Private JSON file, or - for stdin} {--format=json : Output text or json}';

    protected $description = 'Read or preview/apply native collaboration with the same scoped actions and receipts as REST';

    public function handle(CollaborationTransportService $collaboration): int
    {
        $operation = (string) $this->argument('operation');
        if (! in_array($operation, CollaborationTransportService::OPERATIONS, true)) {
            $this->error('Unknown collaboration operation.');

            return self::INVALID;
        }
        $workspace = $this->workspaceByPublicId((string) $this->argument('workspace'));
        if ($workspace === null) {
            $this->error('Workspace not found.');

            return self::FAILURE;
        }
        $actor = $this->userByPublicId((string) $this->option('actor'));
        if (is_int($actor)) {
            return $actor;
        }
        try {
            $input = $this->inputJson();
            /** @var array{id?:string,parent_type?:string,idempotency_key?:string,payload?:array<string,mixed>} $data */
            $data = Validator::make($input, ['id' => ['sometimes', 'uuid'], 'parent_type' => ['sometimes', 'in:task,topic'],
                'idempotency_key' => ['sometimes', 'string', 'max:255'], 'payload' => ['sometimes', 'array']])->validate();
            $write = ! in_array($operation, ['topics.list', 'topics.get', 'comments.list'], true);
            $result = $write
                ? $this->preview(fn (): array => $collaboration->mutateNative($actor, $workspace, (string) $this->argument('client'), $data['idempotency_key'] ?? '', $operation, $data['id'] ?? null, $data['parent_type'] ?? null, $data['payload'] ?? []))
                : $collaboration->read($actor, $workspace, (string) $this->argument('client'), $operation, $data['id'] ?? null, $data['parent_type'] ?? null, $data['payload'] ?? [], CollaborationAudience::Internal);
            if ($this->option('format') === 'json') {
                $this->json(['dry_run' => $write && ! $this->applying(), 'operation' => 'collaboration.'.$operation, ...$result]);
            } else {
                $this->info($write && ! $this->applying() ? 'Preview succeeded; use --apply to write.' : 'Collaboration operation completed.');
            }

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::INVALID;
        } catch (ModelNotFoundException|HttpExceptionInterface) {
            $this->error('Content not found, access refused, or the expected version is stale.');

            return self::FAILURE;
        } catch (JsonException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }
    }

    /** @return array<string, mixed> */
    private function inputJson(): array
    {
        $path = (string) $this->option('input');
        if ($path !== '-' && (! is_file($path) || is_link($path) || (fileperms($path) & 0077) !== 0)) {
            throw new RuntimeException('Input must be an owner-only private JSON file or stdin.');
        }
        $raw = file_get_contents($path === '-' ? 'php://stdin' : $path, length: 1_048_577);
        if (! is_string($raw) || strlen($raw) > 1_048_576) {
            throw new RuntimeException('JSON input must be at most 1 MiB.');
        }
        if (! json_decode($raw, flags: JSON_THROW_ON_ERROR) instanceof \stdClass) {
            throw new RuntimeException('JSON input must be an object.');
        }

        return json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    }
}
