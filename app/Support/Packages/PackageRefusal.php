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
    case RequestRefused = 'package_request_refused';
    case RequestNotOpen = 'package_request_not_open';
    case RequestNotClosed = 'package_request_not_closed';
    case PackageNotOffered = 'package_not_offered';
    case InvitationsOff = 'package_invitations_off';
    case AddressHeldElsewhere = 'package_address_held_elsewhere';

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
            // Deliberately the same sentence for every reason a public request
            // is turned away (PUB-5): a bot learns nothing from it.
            self::RequestRefused => 'We could not send this request. Please try again later.',
            self::RequestNotOpen => 'This request has already been decided.',
            self::RequestNotClosed => 'Only a decided request can be deleted.',
            self::PackageNotOffered => 'This package is not published, so it cannot be offered. Publish it again first.',
            self::InvitationsOff => 'Portal invitations are switched off, so someone without an account here cannot be invited.',
            self::AddressHeldElsewhere => 'That email address belongs to an account that does not sign in through the identity provider, so it cannot be given portal access.',
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
