<?php

namespace App\Console\Commands\Attachments;

use App\Console\Commands\Concerns\FindsUserByPublicId;
use App\Console\Commands\MutationCommand;
use App\Services\AgentApi\AgentExpenseReceiptService;
use App\Services\Files\AttachmentMutationOrigin;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class DeleteExpenseReceiptCommand extends MutationCommand
{
    use FindsUserByPublicId;

    protected $signature = 'svc:expense-receipts:delete
        {workspace : Workspace public UUID}
        {expense : Expense public UUID}
        {receipt : Receipt public UUID}
        {--actor= : Current internal manager public UUID}
        {--expected-version= : Current receipt version}
        {--expected-parent-version= : Current expense version}
        {--confirm : Confirm deletion when applying}
        {--key= : Stable mutation retry key}';

    protected $description = 'Preview deletion of an expense receipt; --apply --confirm records the deletion';

    public function handle(AgentExpenseReceiptService $receipts): int
    {
        try {
            $workspace = $this->workspaceByPublicId((string) $this->argument('workspace'));
            $actor = $this->userByPublicId((string) $this->option('actor'));
            if ($workspace === null || is_int($actor)) {
                return self::INVALID;
            }
            $data = Validator::make(['expense' => $this->argument('expense'), 'receipt' => $this->argument('receipt'),
                'key' => $this->option('key')], ['expense' => ['required', 'uuid'], 'receipt' => ['required', 'uuid'],
                    'key' => [$this->applying() ? 'required' : 'nullable', 'string', 'max:255']])->validate();
            $body = ['expected_version' => $this->option('expected-version'),
                'expected_parent_version' => $this->option('expected-parent-version'), 'confirm' => true];
            if ($this->applying()) {
                abort_unless($this->option('confirm'), 422, 'Explicit --confirm is required with --apply.');
                $result = $receipts->delete($actor, $workspace, 'svc-cli:expense-receipts', $data['key'], $data['expense'], $data['receipt'], $body, AttachmentMutationOrigin::Native);
            } else {
                $result = $receipts->deletionPreflight($actor, $workspace, $data['expense'], $data['receipt'], $body);
            }
            $this->json($result);

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->error($field.': '.implode(' ', $messages));
            }

            return self::INVALID;
        } catch (ModelNotFoundException|HttpExceptionInterface) {
            $this->error('Receipt deletion is unavailable, unauthorized or stale. Read the current expense and receipt before retrying.');

            return self::FAILURE;
        }
    }
}
