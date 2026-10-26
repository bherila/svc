<?php

namespace App\Console\Commands\Attachments;

use App\Console\Commands\Concerns\FindsUserByPublicId;
use App\Console\Commands\MutationCommand;
use App\Services\Files\AttachmentAudience;
use App\Services\Files\AttachmentDeletion;
use App\Services\Files\AttachmentMutationOrigin;
use App\Services\Files\AttachmentPageQuery;
use App\Services\Files\AttachmentParentReference;
use App\Services\Files\AttachmentSharingAction;
use App\Services\Files\AttachmentSharingTransport;
use App\Services\Files\AttachmentVisibilityChange;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Native authority; previews never enter storage, mutation receipt or audit adapters. */
final class AttachmentsCommand extends MutationCommand
{
    use FindsUserByPublicId;

    protected $signature = 'svc:attachments
        {operation : list, get, upload, visibility.update, or delete}
        {workspace : Workspace public UUID}
        {record_type : Legacy generic parent, topic or comment}
        {record_id : Parent public UUID}
        {--company= : Company public UUID, required only for topic/comment}
        {--actor= : Current internal account public UUID}
        {--attachment= : Attachment public UUID for get/sharing/deletion}
        {--input= : Owner-only JSON request file, or - for standard input}
        {--key= : Retry key for a mutation}
        {--limit=100 : Maximum rows in a read page}
        {--cursor= : Opaque continuation cursor}';

    protected $description = 'Read files and preview attachment sharing or collaboration uploads before applying';

    public function handle(AttachmentSharingTransport $files, AttachmentSharingAction $action): int
    {
        try {
            $workspace = $this->workspaceByPublicId((string) $this->argument('workspace'));
            $actor = $this->userByPublicId((string) $this->option('actor'));
            if ($workspace === null || is_int($actor)) {
                return self::INVALID;
            }
            $operation = (string) $this->argument('operation');
            $data = Validator::make(['operation' => $operation, 'type' => $this->argument('record_type'),
                'id' => $this->argument('record_id'), 'company' => $this->option('company'), 'attachment' => $this->option('attachment'),
                'key' => $this->option('key')], ['operation' => ['required', 'in:list,get,upload,visibility.update,delete'],
                    'type' => ['required', 'in:company,project,task,proposal,agreement,invoice,topic,comment'], 'id' => ['required', 'uuid'],
                    'company' => ['nullable', 'uuid'], 'attachment' => [in_array($operation, ['get', 'visibility.update', 'delete'], true) ? 'required' : 'nullable', 'uuid'],
                    'key' => [in_array($operation, ['list', 'get'], true) ? 'nullable' : 'required', 'string', 'max:255']])->validate();
            $reference = new AttachmentParentReference($data['type'], $data['id'], $data['company']);
            if ($operation === 'list') {
                $result = $files->listing($actor, $workspace, $reference, AttachmentAudience::Internal,
                    AttachmentPageQuery::fromInput(['limit' => $this->option('limit'), 'cursor' => $this->option('cursor')]));
            } elseif ($operation === 'get') {
                $result = $files->metadata($actor, $workspace, $reference, $data['attachment']);
            } else {
                $payload = $this->privateInput();
                if ($operation === 'upload') {
                    abort_unless($reference->collaboration(), 422);
                    if (array_diff(array_keys($payload), ['source_path', 'expected_version']) !== []) {
                        throw ValidationException::withMessages(['attachment' => 'Unsupported upload field.']);
                    }
                    $upload = Validator::make($payload, ['source_path' => ['required', 'string'], 'expected_version' => ['required', 'string', 'size:64']])->validate();
                    abort_unless(is_file($upload['source_path']) && is_readable($upload['source_path']), 422);
                    $file = new UploadedFile($upload['source_path'], basename($upload['source_path']), test: true);
                    Validator::make(['file' => $file], ['file' => ['required', 'file', 'max:51200']])->validate();
                    if ($this->applying()) {
                        $result = $files->upload($actor, $workspace, $reference, $file, ['expected_version' => $upload['expected_version']],
                            'svc-cli:attachments', $data['key'], AttachmentMutationOrigin::Native);
                    } else {
                        $action->uploadPreflight($actor, $workspace, $reference, AttachmentMutationOrigin::Native, $upload['expected_version']);
                        $result = ['data' => ['filename' => $file->getClientOriginalName(), 'bytes' => $file->getSize(),
                            'sha256' => hash_file('sha256', $file->getPathname()), 'is_visible_to_client' => false], 'dry_run' => true];
                    }
                } else {
                    $change = $operation === 'visibility.update' ? AttachmentVisibilityChange::fromInput($payload) : AttachmentDeletion::fromInput($payload);
                    if ($operation === 'delete') {
                        abort_unless($reference->collaboration(), 422);
                    }
                    if ($this->applying()) {
                        $result = $operation === 'visibility.update'
                            ? $files->updateVisibility($actor, $workspace, $reference, $data['attachment'], $payload,
                                'svc-cli:attachments', $data['key'], AttachmentMutationOrigin::Native)
                            : $files->delete($actor, $workspace, $reference, $data['attachment'], $payload,
                                'svc-cli:attachments', $data['key'], AttachmentMutationOrigin::Native);
                    } else {
                        $action->mutationPreflight($actor, $workspace, $reference, $data['attachment'], $change->expectedVersion, $change->expectedParentVersion);
                        $result = ['data' => ['attachment_id' => $data['attachment'], 'operation' => $operation], 'dry_run' => true];
                    }
                }
            }
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->error($field.': '.implode(' ', $messages));
            }

            return self::INVALID;
        } catch (ModelNotFoundException|HttpExceptionInterface|JsonException|InvalidArgumentException) {
            $this->error('The attachment operation is unavailable, invalid or stale. Read it again before saving.');

            return self::FAILURE;
        }
        $this->json($result);

        return self::SUCCESS;
    }

    /** @return array<string,mixed> */
    private function privateInput(): array
    {
        $input = $this->option('input');
        abort_unless(is_string($input) && $input !== '', 422);
        if ($input !== '-') {
            abort_unless(is_file($input) && is_readable($input), 422);
            $permissions = fileperms($input);
            abort_unless($permissions !== false && ($permissions & 0077) === 0
                && function_exists('posix_geteuid') && fileowner($input) === posix_geteuid(), 422);
        }
        $bytes = file_get_contents($input === '-' ? 'php://stdin' : $input, length: 1048577);
        abort_unless(is_string($bytes) && strlen($bytes) <= 1048576 && str_starts_with(ltrim($bytes), '{'), 422);
        $payload = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        abort_unless(is_array($payload), 422);

        return $payload;
    }
}
