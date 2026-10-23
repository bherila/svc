<?php

namespace App\Services\Collaboration;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final readonly class TopicDraft
{
    private function __construct(public TopicKind $kind, public string $title, public string $body, public ?string $projectId, public bool $visibleToClient) {}

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input): self
    {
        /** @var array{kind:string,title:string,body:string,project_id?:string|null,is_visible_to_client?:bool} $data */
        $data = Validator::make($input, [
            'kind' => ['required', Rule::enum(TopicKind::class)],
            'title' => ['required', 'string', 'max:255'], 'body' => ['required', 'string', 'max:50000'],
            'project_id' => ['nullable', 'uuid'], 'is_visible_to_client' => ['sometimes', 'boolean'],
            'author_user_id' => ['prohibited'], 'author_name' => ['prohibited'], 'workspace_id' => ['prohibited'],
        ])->validate();

        return new self(TopicKind::from($data['kind']), trim($data['title']), trim($data['body']), $data['project_id'] ?? null, (bool) ($data['is_visible_to_client'] ?? false));
    }
}
