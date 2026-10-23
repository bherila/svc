<?php

namespace App\Services\Collaboration;

use Illuminate\Support\Facades\Validator;

final readonly class CommentDraft
{
    private function __construct(public string $body, public bool $visibleToClient, public string $expectedVersion) {}

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input): self
    {
        /** @var array{body:string,is_visible_to_client?:bool,expected_version:string} $data */
        $data = Validator::make($input, [
            'body' => ['required', 'string', 'max:50000'], 'is_visible_to_client' => ['sometimes', 'boolean'],
            'expected_version' => ['required', 'string', 'size:64'],
            'author_user_id' => ['prohibited'], 'author_name' => ['prohibited'], 'workspace_id' => ['prohibited'],
            'client_task_id' => ['prohibited'], 'client_collaboration_topic_id' => ['prohibited'],
        ])->validate();

        return new self(trim($data['body']), (bool) ($data['is_visible_to_client'] ?? false), $data['expected_version']);
    }
}
