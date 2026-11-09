<?php

namespace App\Support\Packages;

/**
 * Whether a package version may be published, with every reason it may not.
 *
 * Pure: the caller reads whether the workspace can take payments - and, when
 * publishing, which currencies its Stripe account can charge (EDGE-5) - and
 * passes them in, so these rules are tested without a database or a processor.
 */
final class PackagePublishRules
{
    /**
     * @param  list<string>|null  $chargeableCurrencies  the upper-case ISO codes the Stripe account can charge; null when not asked (a page showing readiness asks no processor)
     * @return list<PackageRefusal> every refusal that applies, in a stable order
     */
    public static function refusals(PackageTerms $terms, bool $stripeConnected, ?array $chargeableCurrencies = null): array
    {
        $refusals = [];

        if ($terms->components === [] && ($terms->retainerAmount ?? 0) === 0 && $terms->hourlyRateAmount === null) {
            $refusals[] = PackageRefusal::NothingPriced;
        }

        if ($terms->involvesPayment() && ! $stripeConnected) {
            $refusals[] = PackageRefusal::PaymentNeedsStripe;
        }

        // EDGE-5: money the account cannot take in this currency is a sign-up
        // nobody can finish.
        if ($terms->involvesPayment() && $stripeConnected && $chargeableCurrencies !== null
            && ! in_array(strtoupper($terms->currency), $chargeableCurrencies, true)) {
            $refusals[] = PackageRefusal::CurrencyNotChargeable;
        }

        // PKG-5: an invoice nobody reviews may only carry what was agreed in
        // advance. Usage (hours, retainer overage) needs a person to issue it,
        // and a one-time package has no cycle to issue.
        if ($terms->invoiceIssuing === PackageInvoiceIssuing::Automatic && ($terms->billsByUsage() || ! $terms->isRecurring())) {
            $refusals[] = PackageRefusal::AutoIssueNeedsFixedPrice;
        }

        if ($terms->requireAutopay && ($terms->autopayLimitAmount === null || $terms->autopayLimitAmount <= 0)) {
            $refusals[] = PackageRefusal::AutopayNeedsLimit;
        }

        if ($terms->payBeforeActivation && ! $terms->chargesAtSignup()) {
            $refusals[] = PackageRefusal::PayBeforeActivationNeedsCharge;
        }

        if ($terms->chargeSetupFeeAtSignup && ! $terms->hasSetupFee()) {
            $refusals[] = PackageRefusal::SetupFeeAtSignupNeedsSetupFee;
        }

        // The first period is the billing engine's first cycle; a one-time
        // package has none.
        if ($terms->chargeFirstPeriod && ! $terms->isRecurring()) {
            $refusals[] = PackageRefusal::FirstPeriodNeedsRecurringCadence;
        }

        if ($terms->minimumTermMonths !== null && $terms->minimumTermMonths > 0 && ! $terms->isRecurring()) {
            $refusals[] = PackageRefusal::MinimumTermNeedsRecurringCadence;
        }

        return $refusals;
    }
}
