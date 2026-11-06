<?php

declare(strict_types=1);

namespace App\Support\Billing\Document;

use App\Support\Billing\InvoiceLineType;

/**
 * The sections an invoice document groups its lines into, in print order.
 *
 * Every {@see InvoiceLineType} maps to exactly one section through an
 * exhaustive `match` with no default arm, so a new line type fails static
 * analysis until someone decides where it prints. A stored type that is no
 * longer a case (a row written by an older release) is the one thing a `match`
 * cannot see; it prints under {@see self::Adjustments} rather than vanishing,
 * because a line that is billed must be printed.
 */
enum DocumentSection: string
{
    case Time = 'time';
    case RecurringServices = 'recurring_services';
    case Expenses = 'expenses';
    case DeferredWork = 'deferred_work';
    case Adjustments = 'adjustments';

    public function title(): string
    {
        return match ($this) {
            self::Time => 'Time',
            self::RecurringServices => 'Recurring services',
            self::Expenses => 'Expenses',
            self::DeferredWork => 'Deferred work',
            self::Adjustments => 'Adjustments and credits',
        };
    }

    public static function forType(InvoiceLineType $type): self
    {
        return match ($type) {
            InvoiceLineType::Retainer,
            InvoiceLineType::PriorMonthRetainer,
            InvoiceLineType::PriorMonthBillable,
            InvoiceLineType::AdditionalHours,
            InvoiceLineType::Subcontractor => self::Time,
            InvoiceLineType::RecurringItem => self::RecurringServices,
            InvoiceLineType::Expense => self::Expenses,
            InvoiceLineType::CarriedDeferredApplied,
            InvoiceLineType::CarriedDeferredBilled,
            InvoiceLineType::DeferredBuyDown => self::DeferredWork,
            InvoiceLineType::Credit,
            InvoiceLineType::Adjustment,
            InvoiceLineType::Reconciliation,
            InvoiceLineType::Milestone => self::Adjustments,
        };
    }

    /** The section for a stored `type` string. */
    public static function forStored(string $type): self
    {
        $known = InvoiceLineType::tryFrom($type);

        return $known === null ? self::Adjustments : self::forType($known);
    }
}
