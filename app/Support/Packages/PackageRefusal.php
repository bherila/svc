<?php

namespace App\Support\Packages;

/**
 * Why a package change was refused. Codes are part of the agent API.
 *
 * The publish refusals are term combinations that would leave a client unable
 * to sign up, or billed in a way the package cannot honour; those rules are
 * {@see PackagePublishRules}.
 */
enum PackageRefusal: string
{
    case LinkNameTaken = 'package_link_name_taken';
    case NotPublished = 'package_not_published';

    case NothingToPublish = 'package_nothing_to_publish';
    case NothingPriced = 'package_nothing_priced';
    case PaymentNeedsStripe = 'package_payment_needs_stripe';
    case AutoIssueNeedsFixedPrice = 'package_auto_issue_needs_fixed_price';
    case AutopayNeedsLimit = 'package_autopay_needs_limit';
    case PayBeforeActivationNeedsCharge = 'package_pay_before_activation_needs_charge';
    case SetupFeeAtSignupNeedsSetupFee = 'package_setup_fee_at_signup_needs_setup_fee';
    case MinimumTermNeedsRecurringCadence = 'package_minimum_term_needs_recurring_cadence';

    public function message(): string
    {
        return match ($this) {
            self::LinkNameTaken => 'Another package in this workspace already uses that link name.',
            self::NotPublished => 'Only a published package can be retired.',
            self::NothingToPublish => 'There is no draft to publish.',
            self::NothingPriced => 'Add a component, a retainer or an hourly rate before publishing.',
            self::PaymentNeedsStripe => 'This package takes a payment at sign-up, so the workspace needs a Stripe connection before it can be published.',
            self::AutoIssueNeedsFixedPrice => 'Only fixed-price packages can issue invoices automatically. Remove the hourly rate and retainer, or create drafts instead.',
            self::AutopayNeedsLimit => 'Set the autopay limit the client will authorize.',
            self::PayBeforeActivationNeedsCharge => 'Starting only once paid needs something to pay at sign-up: the setup fee or the first period.',
            self::SetupFeeAtSignupNeedsSetupFee => 'Add a setup fee component, or stop charging one at sign-up.',
            self::MinimumTermNeedsRecurringCadence => 'A minimum term needs a recurring billing cadence.',
        };
    }
}
