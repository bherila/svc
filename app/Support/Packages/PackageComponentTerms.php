<?php

declare(strict_types=1);

namespace App\Support\Packages;

use App\Support\Billing\ChargeCadence;

/** One priced line of a package's terms, before or after it is stored. */
final readonly class PackageComponentTerms
{
    public function __construct(
        public PackageComponentKind $kind,
        public string $description,
        public ChargeCadence $cadence,
        public int $amount,
        public string $quantity,
        public bool $isTaxable,
    ) {}

    /** @param array{kind: string, description: string, cadence?: string|null, amount: int|string, quantity?: int|float|string|null, is_taxable?: bool|null} $input */
    public static function fromArray(array $input): self
    {
        $kind = PackageComponentKind::from($input['kind']);

        return new self(
            $kind,
            trim($input['description']),
            // A setup fee is charged once by definition, whatever was sent.
            $kind === PackageComponentKind::SetupFee ? ChargeCadence::OneTime : ChargeCadence::from($input['cadence'] ?? ChargeCadence::Monthly->value),
            (int) $input['amount'],
            number_format((float) ($input['quantity'] ?? 1), 3, '.', ''),
            (bool) ($input['is_taxable'] ?? false),
        );
    }

    /** The line's total in minor units: amount × quantity, rounded half up. */
    public function total(): int
    {
        // @infection-ignore-all Equivalent: PHP multiplies an int by the stored decimal string numerically even under strict types (strictness covers arguments and returns, not arithmetic); the cast is for static analysis.
        $quantity = (float) $this->quantity;

        return (int) round($this->amount * $quantity);
    }

    /** @return array{kind: string, description: string, cadence: string, amount: int, quantity: string, is_taxable: bool} */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'description' => $this->description,
            'cadence' => $this->cadence->value,
            'amount' => $this->amount,
            'quantity' => $this->quantity,
            'is_taxable' => $this->isTaxable,
        ];
    }
}
