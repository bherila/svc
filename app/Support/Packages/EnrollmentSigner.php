<?php

namespace App\Support\Packages;

use App\Models\ServicePackageEnrollment;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

/**
 * Who signs an acceptance, as they typed it (ACC-2).
 *
 * The name is required and the title optional, both trimmed and at most 200
 * characters, and the contact must tick that they accept the terms. A repeat
 * acceptance by the same signer - the same person, name and title - is the
 * same act and changes nothing; anyone else is refused.
 */
final readonly class EnrollmentSigner
{
    public const array RULES = [
        'signer_name' => ['required', 'string', 'max:200'],
        'signer_title' => ['nullable', 'string', 'max:200'],
        'accepted_terms' => ['accepted'],
    ];

    public function __construct(public string $name, public ?string $title) {}

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input): self
    {
        foreach (['signer_name', 'signer_title'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = trim($input[$field]);
            }
        }
        $data = Validator::make($input, self::RULES)->validate();
        $title = $data['signer_title'] ?? null;

        return new self((string) $data['signer_name'], is_string($title) && $title !== '' ? $title : null);
    }

    /** Whether this is the signer an accepted enrollment already records. */
    public function signed(ServicePackageEnrollment $enrollment, User $user): bool
    {
        return $enrollment->accepted_by_user_id === $user->id
            && $enrollment->signer_name === $this->name
            && $enrollment->signer_title === $this->title;
    }
}
