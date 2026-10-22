<?php

namespace App\Services\Clients;

final readonly class ContactDetails
{
    public function __construct(
        public ?string $firstName,
        public ?string $lastName,
        public ?string $title,
        public ?string $email,
        public ?string $phone,
        public ?string $notes,
        public bool $archived,
    ) {}

    /** @param array{first_name?: ?string, last_name?: ?string, title?: ?string, email?: ?string, phone?: ?string, notes?: ?string, is_archived?: mixed} $attributes */
    public static function fromValidated(array $attributes): self
    {
        return new self($attributes['first_name'] ?? null, $attributes['last_name'] ?? null,
            $attributes['title'] ?? null, $attributes['email'] ?? null, $attributes['phone'] ?? null,
            $attributes['notes'] ?? null, (bool) ($attributes['is_archived'] ?? false));
    }

    /** @return array<string, string|bool|null> */
    public function attributes(): array
    {
        return ['first_name' => $this->firstName, 'last_name' => $this->lastName,
            'title' => $this->title, 'email' => $this->email, 'phone' => $this->phone,
            'notes' => $this->notes, 'is_archived' => $this->archived];
    }
}
