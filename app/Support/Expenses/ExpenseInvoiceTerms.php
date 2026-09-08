<?php

namespace App\Support\Expenses;

use App\Models\ClientExpense;
use App\Services\Billing\MoneyService;
use DomainException;

/** An approved expense is billed in its stored minor units, without markup. */
final readonly class ExpenseInvoiceTerms
{
    public function __construct(public int $amount, public string $currency)
    {
        if ($amount <= 0 || MoneyService::currency($currency) !== $currency) {
            throw new DomainException('The expense has invalid stored pricing.');
        }
    }

    public static function from(ClientExpense $expense): self
    {
        return new self($expense->amount, $expense->currency);
    }
}
