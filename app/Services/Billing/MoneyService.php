<?php

namespace App\Services\Billing;

use InvalidArgumentException;

final class MoneyService
{
    public static function currency(mixed $currency): string
    {
        if (! is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException('Currency must be an uppercase ISO 4217 code.');
        }

        return $currency;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{subtotal_amount:int, tax_amount:int, total_amount:int}
     */
    public static function invoiceTotals(array $lines): array
    {
        $subtotal = 0;
        $tax = 0;

        foreach ($lines as $line) {
            $unit = self::nonNegativeInteger($line['unit_amount'] ?? null, 'unit_amount');
            $lineTax = self::nonNegativeInteger($line['tax_amount'] ?? 0, 'tax_amount');
            $subtotal = self::safeAdd($subtotal, self::multiply(self::decimal($line['quantity'] ?? null), $unit));
            $tax = self::safeAdd($tax, $lineTax);
        }

        return [
            'subtotal_amount' => $subtotal,
            'tax_amount' => $tax,
            'total_amount' => self::safeAdd($subtotal, $tax),
        ];
    }

    public static function nonNegativeInteger(mixed $value, string $name): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $integer = (int) $value;
        } else {
            throw new InvalidArgumentException("{$name} must be a non-negative integer minor-unit amount.");
        }

        if ($integer < 0) {
            throw new InvalidArgumentException("{$name} must be non-negative.");
        }

        return $integer;
    }

    /** @return array{numerator:int, denominator:int} */
    private static function decimal(mixed $value): array
    {
        if (is_int($value)) {
            $value = (string) $value;
        } elseif (is_float($value)) {
            $value = rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
        }

        if (! is_string($value) || preg_match('/^(\d+)(?:\.(\d{1,4}))?$/', $value, $matches) !== 1) {
            throw new InvalidArgumentException('quantity must be a non-negative decimal with at most four places.');
        }

        $fraction = $matches[2] ?? '';
        $scale = strlen($fraction);
        $denominator = 10 ** $scale;
        $numerator = ((int) $matches[1]) * $denominator + (int) str_pad($fraction, $scale, '0');

        if ($numerator <= 0) {
            throw new InvalidArgumentException('quantity must be greater than zero.');
        }

        return ['numerator' => $numerator, 'denominator' => $denominator];
    }

    /** @param array{numerator:int, denominator:int} $quantity */
    private static function multiply(array $quantity, int $unit): int
    {
        $whole = intdiv($quantity['numerator'], $quantity['denominator']);
        $fraction = $quantity['numerator'] % $quantity['denominator'];
        if ($unit !== 0 && $whole > intdiv(PHP_INT_MAX, $unit)) {
            throw new InvalidArgumentException('Line total exceeds the supported integer range.');
        }

        $wholeAmount = $whole * $unit;
        $fractionProduct = $fraction * $unit;
        $fractionAmount = intdiv($fractionProduct, $quantity['denominator']);
        $remainder = $fractionProduct % $quantity['denominator'];

        return self::safeAdd(
            $wholeAmount,
            $fractionAmount + ($remainder * 2 >= $quantity['denominator'] ? 1 : 0),
        );
    }

    private static function safeAdd(int $left, int $right): int
    {
        if ($right > PHP_INT_MAX - $left) {
            throw new InvalidArgumentException('Money total exceeds the supported integer range.');
        }

        return $left + $right;
    }
}
