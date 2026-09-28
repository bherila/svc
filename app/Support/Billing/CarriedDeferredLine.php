<?php

namespace App\Support\Billing;

/**
 * The two invoice lines that settle re-carried deferred work.
 *
 * Deferred work an invoice applied beyond the free capacity of the month it
 * drew on stays linked to that invoice's $0 line, so it cannot be linked
 * again; the ledger carries the excess forward as a quantity instead. A later
 * invoice settles that quantity with one of these lines, which link no time:
 * applied from a month's spare capacity at no charge, or billed at the hourly
 * rate on termination. The ledger recognises them by their line type alone -
 * a type no manual line may carry - and the description is display text.
 */
enum CarriedDeferredLine: string
{
    case Applied = 'Carried deferred work applied to retainer';
    case BilledOnTermination = 'Carried deferred work billed on agreement termination';

    public function describe(float $hours): string
    {
        return sprintf('%s (%s)', $this->value, HoursQuantity::format($hours));
    }

    public function lineType(): InvoiceLineType
    {
        return match ($this) {
            self::Applied => InvoiceLineType::CarriedDeferredApplied,
            self::BilledOnTermination => InvoiceLineType::CarriedDeferredBilled,
        };
    }

    /** Which carried-deferred line a line of this type is, if it is one. */
    public static function of(string $type): ?self
    {
        foreach (self::cases() as $case) {
            if ($type === $case->lineType()->value) {
                return $case;
            }
        }

        return null;
    }
}
