<?php

namespace App\Support\AgentApi;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** The published JSON contract requires true, rather than a truthy form value. */
final class ExplicitConfirmation implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value !== true) {
            $fail('Explicit confirmation must be the boolean true.');
        }
    }
}
