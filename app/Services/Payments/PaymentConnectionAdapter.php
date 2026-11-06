<?php

namespace App\Services\Payments;

use App\Support\Payments\PaymentProcessor;

/**
 * One payment processor's side of a workspace connection: how to check a pasted
 * credential, and how to make the processor call back to this workspace's own
 * webhook URL. A processor that connects another way (OAuth, Stripe Connect) is
 * a further implementation; nothing outside the adapters knows how a
 * credential was obtained.
 */
interface PaymentConnectionAdapter
{
    public function processor(): PaymentProcessor;

    /**
     * Check the credential with a cheap read and say which account it opens.
     *
     * @throws PaymentCredentialsRejected
     */
    public function verify(PaymentCredentials $credentials): VerifiedAccount;

    /**
     * Have the processor deliver events to `$url`, when it can be asked to.
     * Null means the owner registers the URL by hand and supplies the secret.
     *
     * @throws PaymentCredentialsRejected
     */
    public function registerWebhook(PaymentCredentials $credentials, string $url): ?RegisteredWebhook;

    /** Delete the endpoint {@see registerWebhook()} made. Best effort; never throws. */
    public function removeWebhook(PaymentCredentials $credentials, string $endpointId): void;
}
