<?php

namespace App\Rules;

use App\Support\RepositoryReference;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * The value must be a repository reference something can be made of.
 *
 * Paired with `nullable`, never instead of it. Clearing the field and typing
 * something unusable both leave {@see RepositoryReference::normalize()}
 * returning `null`, and only the two rules together can tell them apart: a
 * blank passes here and is stored as `null`, while `my project on github`
 * fails and is reported on the field. Without this rule the second case would
 * be silently accepted and stored as "nobody has said", so the operator would
 * be told the project saved and the mapping would simply never match.
 */
final class NormalizableRepository implements ValidationRule
{
    /** @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return;
        }

        if (! is_string($value) || ! RepositoryReference::isNormalizable($value)) {
            $fail('The :attribute must name a repository as host/owner/name, for example github.com/owner/name or git@github.com:owner/name.git.');
        }
    }
}
