<?php

namespace App\Support\Packages;

/** A question a package's request form asks, identified by a key stable across versions. */
final readonly class IntakeQuestion
{
    public function __construct(
        public string $key,
        public string $label,
        public IntakeQuestionKind $kind,
        public bool $required,
    ) {}

    /** @param array{key: string, label: string, kind: string, required?: bool|null} $input */
    public static function fromArray(array $input): self
    {
        return new self($input['key'], trim($input['label']), IntakeQuestionKind::from($input['kind']), (bool) ($input['required'] ?? false));
    }

    /** @return array{key: string, label: string, kind: string, required: bool} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'kind' => $this->kind->value, 'required' => $this->required];
    }
}
