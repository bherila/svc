<?php

namespace App\Support\Billing;

/** How an allocated deferred entry participates in retainer capacity. */
enum DeferredWorkDisposition
{
    case Waiting;
    case RetainerApplied;
    case BilledOnTermination;
    case SettledOutsideRetainer;

    public static function fromAllocation(?string $lineType): self
    {
        if ($lineType === null) {
            return self::Waiting;
        }

        $knownType = InvoiceLineType::tryFrom($lineType);
        // Preserve the interpretation of legacy, unnamed allocation types.
        if ($knownType === null) {
            return self::RetainerApplied;
        }

        return match ($knownType) {
            InvoiceLineType::DeferredBuyDown => self::SettledOutsideRetainer,
            InvoiceLineType::AdditionalHours => self::BilledOnTermination,
            InvoiceLineType::Retainer, InvoiceLineType::PriorMonthRetainer,
            InvoiceLineType::PriorMonthBillable, InvoiceLineType::Credit,
            InvoiceLineType::Expense, InvoiceLineType::Milestone,
            InvoiceLineType::Adjustment, InvoiceLineType::RecurringItem,
            InvoiceLineType::Reconciliation, InvoiceLineType::Subcontractor,
            InvoiceLineType::CarriedDeferredApplied, InvoiceLineType::CarriedDeferredBilled => self::RetainerApplied,
        };
    }

    public function usesCapacity(): bool
    {
        return match ($this) {
            self::RetainerApplied, self::BilledOnTermination => true,
            self::Waiting, self::SettledOutsideRetainer => false,
        };
    }

    public function drawsAsDeferred(): bool
    {
        return match ($this) {
            self::RetainerApplied => true,
            self::Waiting, self::BilledOnTermination, self::SettledOutsideRetainer => false,
        };
    }
}
