<?php

namespace App\Support\Packages;

/** One line of what sign-up charges: a setup fee, or the first billing period with its dates. */
final readonly class SignupChargeLine
{
    public function __construct(
        public SignupChargeKind $kind,
        public string $description,
        public int $amount,
        public ?string $periodStart,
        public ?string $periodEnd,
    ) {}

    /** @return array{kind: string, description: string, amount: int, period_start: string|null, period_end: string|null} */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'description' => $this->description,
            'amount' => $this->amount,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
        ];
    }
}
