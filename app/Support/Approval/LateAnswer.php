<?php

declare(strict_types=1);

namespace App\Support\Approval;

use App\Support\Billing\SubcontractorBillingMode;

/**
 * An hour answered after the invoice reconciling its own period was charged
 * (docs/ux-overhaul.md 7b″).
 *
 * That period's pool was reconciled while the hour was held, so it can no
 * longer bill there. Work the retainer ledger reads becomes deferred work: it
 * joins the deferred backlog, draws on later free capacity or is settled by a
 * buy-down, and - waiting - takes no capacity, so no later draft's charges
 * depend on it until a line takes it. A flat-hourly subcontractor hour is
 * priced alone and is carried to the next draft as it is; direct billing
 * never reaches the client.
 *
 * Pure and database-free; the caller supplies the facts.
 */
final class LateAnswer
{
    /**
     * @param  string|null  $billingMode  the stored subcontractor billing mode, raw
     * @param  bool  $periodCharged  a charged cadence invoice of its agreement scope covers its date
     */
    public static function defers(?string $billingMode, bool $periodCharged): bool
    {
        return $periodCharged
            && ($billingMode === null || $billingMode === SubcontractorBillingMode::Retainer->value);
    }
}
