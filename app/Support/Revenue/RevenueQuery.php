<?php

declare(strict_types=1);

namespace App\Support\Revenue;

/** The toggles a revenue read takes, as the dashboard and the planning reads share them. */
final readonly class RevenueQuery
{
    public function __construct(
        public RevenueBasis $basis = RevenueBasis::Invoiced,
        public bool $includeExpenses = true,
        public bool $includeProjectedOverage = false,
        public string $band = 'all',
        public ?string $clientId = null,
    ) {}

    /**
     * The toggles as a screen's query string carries them: `basis`
     * (invoiced or collected), `expenses` and `overage` (1 or 0). Anything
     * missing or unreadable takes its default - invoiced, expenses on,
     * projected overage off - so a mangled link shows the standard figures
     * and never a different basis by accident.
     *
     * @param  array<array-key, mixed>  $input
     */
    public static function fromInput(array $input, ?string $clientId = null): self
    {
        $flag = static fn (mixed $value, bool $default): bool => is_string($value) || is_int($value) || is_bool($value)
            ? (filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default)
            : $default;

        return new self(
            basis: ($input['basis'] ?? null) === RevenueBasis::Collected->value ? RevenueBasis::Collected : RevenueBasis::Invoiced,
            includeExpenses: $flag($input['expenses'] ?? null, true),
            includeProjectedOverage: $flag($input['overage'] ?? null, false),
            clientId: $clientId,
        );
    }

    /** @return array{basis: string, expenses: bool, overage: bool} */
    public function toggles(): array
    {
        return ['basis' => $this->basis->value, 'expenses' => $this->includeExpenses, 'overage' => $this->includeProjectedOverage];
    }
}
