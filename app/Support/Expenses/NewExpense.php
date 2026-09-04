<?php

namespace App\Support\Expenses;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The facts of an expense somebody wants recorded, checked once and then fixed.
 *
 * ## Why this carries no workspace, company or project
 *
 * Deliberately. Tenancy is the one thing a caller can get wrong in a way the
 * database cannot always catch, so it is not something a caller gets to hand
 * over: {@see \App\Queries\Expenses\WorkspaceExpenses} resolves the company and
 * the optional project against the workspace it was constructed for, and adds
 * those columns itself. A DTO that carried a `workspace_id` would be one more
 * place a wrong tenant could enter, and the guard would then live in whichever
 * caller happened to build it.
 *
 * So this object owns exactly the columns that describe the money, and
 * {@see attributes()} returns exactly those. The seam is visible rather than
 * implied, and `WorkspaceExpensesTest` asserts that no tenant column can arrive
 * through it.
 *
 * ## Why the checks are in the constructor
 *
 * An expense is a pass-through charge: it reaches a client's invoice at cost,
 * so the amount and currency on it are the amount and currency they are billed.
 * Checking them at the boundary of the type means every later reader - the
 * approval gate, the invoicing hook, a recurrence that copies one - is reading
 * values that were checked once, rather than each re-deciding what an empty
 * currency or a zero amount means.
 *
 * Money is integer minor units with an ISO 4217 code, as everywhere else in this
 * schema. A non-positive amount is refused rather than clamped: the column is
 * unsigned, so a negative would be rejected by the database as a raw driver
 * error at write time, and a zero-value reimbursement is a request nobody meant
 * to make.
 */
final readonly class NewExpense
{
    public CarbonImmutable $spentOn;

    public int $amount;

    public string $currency;

    public string $description;

    public function __construct(
        CarbonImmutable $spentOn,
        int $amount,
        string $currency,
        string $description,
    ) {
        if ($amount <= 0) {
            throw new InvalidArgumentException('An expense amount must be a positive number of minor units.');
        }

        $normalizedCurrency = strtoupper(trim($currency));

        if (preg_match('/^[A-Z]{3}$/', $normalizedCurrency) !== 1) {
            throw new InvalidArgumentException('An expense currency must be a three-letter ISO 4217 code.');
        }

        $trimmedDescription = trim($description);

        if ($trimmedDescription === '') {
            throw new InvalidArgumentException('An expense needs a description.');
        }

        $this->spentOn = $spentOn->startOfDay();
        $this->amount = $amount;
        $this->currency = $normalizedCurrency;
        $this->description = $trimmedDescription;
    }

    /**
     * The columns this object owns, ready for the persistence boundary.
     *
     * Tenant columns and the lifecycle are absent by construction; adding them
     * is {@see \App\Queries\Expenses\WorkspaceExpenses}' job.
     *
     * @return array{spent_on: CarbonImmutable, amount: int, currency: string, description: string}
     */
    public function attributes(): array
    {
        return [
            'spent_on' => $this->spentOn,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'description' => $this->description,
        ];
    }
}
