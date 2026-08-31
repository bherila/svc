<?php

declare(strict_types=1);

namespace Tests\Fixtures\PHPStan\enums;

use App\Support\Billing\BillingCadence;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\SubcontractorBillingMode;

final class Matches
{
    /** Refused: a case added to the enum lands here silently. */
    public function absorbing(BillingCadence $cadence): string
    {
        return match ($cadence) {
            BillingCadence::Monthly => 'monthly',
            default => 'other',
        };
    }

    /**
     * Refused on a nullable subject too.
     *
     * Strictly worse than the non-nullable version: the arm absorbs the null
     * and every future case at once.
     */
    public function absorbingNullable(?InvoiceKind $kind): string
    {
        return match ($kind) {
            InvoiceKind::AdHoc => 'ad hoc',
            default => 'something else',
        };
    }

    /** Allowed: every case named, so a new one fails to compile. */
    public function exhaustive(SubcontractorBillingMode $mode): string
    {
        return match ($mode) {
            SubcontractorBillingMode::FlatHourly => 'flat',
            SubcontractorBillingMode::Retainer => 'retainer',
            SubcontractorBillingMode::Direct => 'direct',
        };
    }

    /**
     * Allowed: a string has no case list, so there is no mechanical
     * replacement to name. Those sites want the column typed first.
     */
    public function overAString(string $status): string
    {
        return match ($status) {
            'draft' => 'reserved',
            default => 'consumed',
        };
    }
}
