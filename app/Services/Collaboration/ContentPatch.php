<?php

namespace App\Services\Collaboration;

use Illuminate\Support\Facades\Validator;

final readonly class ContentPatch
{
    /** @param array<string, string|bool> $changes */
    private function __construct(public array $changes, public string $expectedVersion) {}

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input, bool $topic): self
    {
        /** @var array{expected_version:string,title?:string,body?:string,is_visible_to_client?:bool} $data */
        $data = Validator::make($input, [
            'title' => $topic ? ['sometimes', 'required', 'string', 'max:255'] : ['prohibited'],
            'body' => ['sometimes', 'required', 'string', 'max:50000'],
            'is_visible_to_client' => ['sometimes', 'boolean'], 'expected_version' => ['required', 'string', 'size:64'],
            'kind' => ['prohibited'], 'project_id' => ['prohibited'], 'author_user_id' => ['prohibited'],
            'author_name' => ['prohibited'], 'workspace_id' => ['prohibited'], 'parent_id' => ['prohibited'],
            'client_task_id' => ['prohibited'], 'client_collaboration_topic_id' => ['prohibited'],
        ])->validate();
        $version = $data['expected_version'];
        unset($data['expected_version']);
        $changes = [];
        foreach ($data as $key => $value) {
            $changes[$key] = $key === 'is_visible_to_client' ? (bool) $value : trim((string) $value);
        }

        return new self($changes, $version);
    }
}
