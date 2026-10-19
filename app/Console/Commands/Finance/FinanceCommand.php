<?php

namespace App\Console\Commands\Finance;

use App\Console\Commands\Concerns\FindsUserByPublicId;
use App\Models\Workspace;
use App\Services\Finance\FinanceReconciliationOperations;
use App\Support\Finance\FinanceCaller;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The console doors to {@see FinanceReconciliationOperations} (#457), the
 * twins of the `finance.*` REST routes and MCP tools.
 *
 * Each command acts as the workspace owner/admin `--user` names, through the
 * same operation, rules and audit as the API: only the door differs. Output
 * is the API's own representation, as JSON, or a short text summary.
 */
abstract class FinanceCommand extends Command
{
    use FindsUserByPublicId;

    /**
     * Run the operation for the caller and return what to print.
     *
     * @return array<string, mixed>
     */
    abstract protected function perform(FinanceReconciliationOperations $operations, FinanceCaller $caller, Workspace $workspace): array;

    /** @param array<string, mixed> $result */
    abstract protected function summarize(array $result): void;

    public function handle(FinanceReconciliationOperations $operations): int
    {
        $format = (string) $this->option('format');
        if (! in_array($format, ['text', 'json'], true)) {
            $this->error('The --format option must be text or json.');

            return self::INVALID;
        }
        $workspaceId = trim((string) $this->option('workspace'));
        if ($workspaceId === '') {
            $this->error('The --workspace option is required.');

            return self::INVALID;
        }
        $user = $this->userByPublicId((string) $this->option('user'));
        if (is_int($user)) {
            return $user;
        }
        $workspace = Workspace::query()->where('public_id', $workspaceId)->first();
        if ($workspace === null) {
            $this->error('No workspace exists for that public UUID.');

            return self::FAILURE;
        }

        try {
            $result = $this->perform($operations, FinanceCaller::operator($user), $workspace);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::INVALID;
        } catch (AuthorizationException) {
            $this->error('The user is not an owner or admin of the workspace.');

            return self::FAILURE;
        } catch (ModelNotFoundException) {
            $this->error('Not found in the requested workspace.');

            return self::FAILURE;
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (HttpExceptionInterface $exception) {
            $this->error($exception->getMessage() !== '' ? $exception->getMessage() : 'Refused.');

            return self::FAILURE;
        }

        if ($format === 'json') {
            $this->line((string) json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->summarize($result);
        }

        return self::SUCCESS;
    }
}
