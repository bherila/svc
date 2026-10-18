<?php

namespace App\Console\Commands\Concerns;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * The credential commands' one way to name a person: the exact public UUID,
 * never an internal id, an email address or a partial match.
 *
 * @phpstan-require-extends Command
 */
trait FindsUserByPublicId
{
    /**
     * The person, or the exit code already explained to the operator:
     * INVALID for something that is not a UUID, FAILURE for no such person.
     */
    private function userByPublicId(string $value): User|int
    {
        $publicId = trim($value);
        if (! Str::isUuid($publicId)) {
            $this->error('The user_public_id must be an exact public UUID.');

            return self::INVALID;
        }

        $user = User::query()->where('public_id', $publicId)->first();
        if ($user === null) {
            $this->error('No user exists for that public UUID.');

            return self::FAILURE;
        }

        return $user;
    }
}
