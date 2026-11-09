<?php

namespace App\Support\Packages;

/**
 * Whether a package version may be published, with every reason it may not.
 *
 * Pure: the caller reads whether the workspace can take payments and passes
 * it in, so these rules are tested without a database or a processor.
 */
final class PackagePublishRules
{
    /** @return list<PackageRefusal> every refusal that applies, in a stable order */
    public static function refusals(PackageTerms $terms, bool $stripeConnected): array
    {
        $refusals = [];

        if ($terms->components === [] && ($terms->retainerAmount ?? 0) === 0 && $terms->hourlyRateAmount === null) {
            $refusals[] = PackageRefusal::NothingPriced;
        }

        if ($terms->involvesPayment() && ! $stripeConnected) {
            $refusals[] = PackageRefusal::PaymentNeedsStripe;
        }

        // PKG-5: an invoice nobody reviews may only carry what was agreed in
        // advance. Usage (hours, retainer overage) needs a person to issue it,
        // and a one-time package has no cycle to issue.
        if ($terms->invoiceIssuing === PackageInvoiceIssuing::Automatic && ($terms->billsByUsage() || ! $terms->isRecurring())) {
            $refusals[] = PackageRefusal::AutoIssueNeedsFixedPrice;
        }

        if ($terms->requireAutopay && ($terms->autopayLimitAmount ?? 0) <= 0) {
            $refusals[] = PackageRefusal::AutopayNeedsLimit;
        }

        if ($terms->payBeforeActivation && ! $terms->chargesAtSignup()) {
            $refusals[] = PackageRefusal::PayBeforeActivationNeedsCharge;
        }

        if ($terms->chargeSetupFeeAtSignup && ! $terms->hasSetupFee()) {
            $refusals[] = PackageRefusal::SetupFeeAtSignupNeedsSetupFee;
        }

        if ($terms->minimumTermMonths !== null && $terms->minimumTermMonths > 0 && ! $terms->isRecurring()) {
            $refusals[] = PackageRefusal::MinimumTermNeedsRecurringCadence;
        }

        return $refusals;
    }
}
