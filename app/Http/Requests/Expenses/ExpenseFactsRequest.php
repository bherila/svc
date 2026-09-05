<?php

namespace App\Http\Requests\Expenses;

use App\Support\Expenses\NewExpense;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The facts of an expense, as they arrive over HTTP.
 *
 * One request for recording and for rewriting, because {@see NewExpense} is
 * one shape for both: `WorkspaceExpenses::update()` takes a whole set of facts
 * rather than a patch of changed columns, so an edit is checked by the same
 * constructor a new expense is. A partial update cannot then smuggle past a
 * rule that only runs on the fields it happens to carry, and this request
 * cannot drift from the create one because there is only one of it.
 *
 * ## The validation here is not the guard
 *
 * `NewExpense` refuses a non-positive amount, a currency that is not three
 * letters, and an empty description, and it does that whoever calls it - the
 * invoicing hook and a future recurrence included. These rules exist so a
 * manager gets a field-level message instead of a 500, not so the domain can
 * relax. Both run, and the domain's is the one that decides.
 *
 * Money arrives in minor units, as everywhere else in this schema. A form that
 * collects "12.50" converts before it posts, because a controller that accepts
 * both is a controller that has to guess which one it got.
 */
class ExpenseFactsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'spent_on' => ['required', 'date'],
            'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'description' => ['required', 'string', 'max:2000'],
            // Absent and null are the same answer here - no project - because
            // an expense belongs to the company and attribution to one of its
            // projects is optional. A form clearing the field posts null.
            'project_id' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /** The checked facts, in the shape the domain takes them. */
    public function facts(): NewExpense
    {
        return new NewExpense(
            spentOn: CarbonImmutable::parse((string) $this->validated('spent_on')),
            amount: (int) $this->validated('amount'),
            currency: (string) $this->validated('currency'),
            description: (string) $this->validated('description'),
        );
    }
}
