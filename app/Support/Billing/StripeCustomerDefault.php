<?php

namespace App\Support\Billing;

/**
 * What a retrieved Stripe customer says its default payment method is, and
 * nothing it does not say clearly: an answer that is not this customer, or
 * has no readable `invoice_settings.default_payment_method`, is never taken
 * for "no default" (that would switch autopay off), it is retried.
 */
final class StripeCustomerDefault
{
    /**
     * @param  array<string, mixed>  $customer  the retrieved customer
     * @return string|null the default method's id; null when Stripe says there is none (or the customer is deleted)
     *
     * @throws StripeEventMustBeRetried
     */
    public static function of(array $customer, string $customerId): ?string
    {
        if (($customer['object'] ?? null) !== 'customer' || ($customer['id'] ?? null) !== $customerId) {
            throw new StripeEventMustBeRetried('Stripe did not return the customer; retry the event.');
        }
        // A deleted customer is returned as a stub: it has no default.
        if (($customer['deleted'] ?? null) === true) {
            return null;
        }
        $settings = $customer['invoice_settings'] ?? null;
        if (! is_array($settings) || ! array_key_exists('default_payment_method', $settings)) {
            throw new StripeEventMustBeRetried('The Stripe customer has no readable default payment method; retry the event.');
        }
        $default = $settings['default_payment_method'];

        return match (true) {
            $default === null => null,
            is_string($default) && $default !== '' => $default,
            // Expanded: the method object itself.
            is_array($default) && is_string($default['id'] ?? null) && $default['id'] !== '' => $default['id'],
            default => throw new StripeEventMustBeRetried('The Stripe customer default payment method is unreadable; retry the event.'),
        };
    }
}
