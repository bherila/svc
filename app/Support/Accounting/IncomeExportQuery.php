<?php

declare(strict_types=1);

namespace App\Support\Accounting;

use App\Services\AgentApi\Operations\AccountingOperations;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** The one parameter of the yearly income export: the calendar year. */
final class IncomeExportQuery
{
    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function year(array $input): int
    {
        $unknown = array_diff(array_keys($input), ['year']);
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, 'This parameter is not supported by the yearly income export.'));
        }
        $validated = Validator::make($input, [
            'year' => ['required', 'integer', 'between:'.AccountingOperations::YEAR_MIN.','.AccountingOperations::YEAR_MAX],
        ])->validate();

        return (int) $validated['year'];
    }
}
