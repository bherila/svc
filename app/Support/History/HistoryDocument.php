<?php

declare(strict_types=1);

namespace App\Support\History;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** A reviewed, plain-text presentation, never an arbitrary source snapshot. */
final readonly class HistoryDocument
{
    /** @param list<array{label: string, value: string}> $fields
     * @param list<array{label: string, columns: list<string>, rows: list<list<string>>}> $tables */
    public function __construct(
        public HistoryKind $kind,
        public string $title,
        public string $body,
        public ?string $author = null,
        public ?string $occurredOn = null,
        public array $fields = [],
        public array $tables = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $validated = Validator::make($data, [
            'kind' => ['required', Rule::enum(HistoryKind::class)],
            'title' => ['required', 'string', 'max:512'], 'body' => ['present', 'string', 'max:2000000'],
            'author' => ['nullable', 'string', 'max:255'], 'occurred_on' => ['nullable', 'string', 'max:40'],
            'fields' => ['present', 'array', 'max:100'], 'fields.*' => ['array:label,value'],
            'fields.*.label' => ['required', 'string', 'max:100'], 'fields.*.value' => ['present', 'string', 'max:2000000'],
            'tables' => ['present', 'array', 'max:10'], 'tables.*' => ['array:label,columns,rows'],
            'tables.*.label' => ['required', 'string', 'max:100'],
            'tables.*.columns' => ['required', 'array', 'max:20'], 'tables.*.columns.*' => ['required', 'string', 'max:100'],
            'tables.*.rows' => ['present', 'array', 'max:10000'], 'tables.*.rows.*' => ['array', 'max:20'],
            'tables.*.rows.*.*' => ['present', 'string', 'max:2000000'],
        ])->validate();

        return new self(HistoryKind::from($validated['kind']), $validated['title'], $validated['body'],
            $validated['author'] ?? null, $validated['occurred_on'] ?? null, $validated['fields'], $validated['tables']);
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        $text = [$this->body, $this->author ?? ''];
        foreach ($this->fields as $field) {
            $text[] = $field['label'].' '.$field['value'];
        }
        foreach ($this->tables as $table) {
            $text[] = $table['label'].' '.implode(' ', $table['columns']);
            foreach ($table['rows'] as $row) {
                $text[] = implode(' ', $row);
            }
        }

        return ['kind' => $this->kind->value, 'title' => $this->title, 'body' => $this->body,
            'author' => $this->author, 'occurred_on' => $this->occurredOn,
            'search_text' => implode("\n", $text),
            'details' => ['fields' => $this->fields, 'tables' => $this->tables]];
    }
}
