<?php

namespace App\Services\Payments;

use App\Services\Square\SquareConfiguration;
use App\Support\Payments\PaymentProcessor;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * Square through an access token the workspace's owner pasted in, with the
 * location payments are taken at and the signature key of the webhook
 * subscription they registered at the URL svc shows them.
 */
final class SquareTokenAdapter implements PaymentConnectionAdapter
{
    private const array BASE_URLS = [
        SquareConfiguration::PRODUCTION => 'https://connect.squareup.com',
        SquareConfiguration::SANDBOX => 'https://connect.squareupsandbox.com',
    ];

    public function __construct(private readonly HttpFactory $http) {}

    public function processor(): PaymentProcessor
    {
        return PaymentProcessor::Square;
    }

    public function verify(PaymentCredentials $credentials): VerifiedAccount
    {
        $environment = $credentials->environment;
        if (! is_string($environment) || ! isset(self::BASE_URLS[$environment])) {
            throw new PaymentCredentialsRejected('Choose production or sandbox.');
        }
        try {
            $response = $this->http->baseUrl(self::BASE_URLS[$environment])
                ->withToken($credentials->secret())
                ->withHeaders(['Square-Version' => (string) config('services.square.version')])
                ->acceptJson()
                ->withoutRedirecting()
                ->timeout(10)
                ->get('/v2/locations');
        } catch (Throwable) {
            throw new PaymentCredentialsRejected('Square could not be reached. Try again.');
        }
        if (! $response->successful()) {
            throw new PaymentCredentialsRejected('Square did not accept this access token for that environment.');
        }
        $locations = $response->json('locations');
        $locations = is_array($locations) ? $locations : [];
        $wanted = $credentials->locationId;
        $location = null;
        foreach ($locations as $candidate) {
            if (is_array($candidate) && ($wanted === null || ($candidate['id'] ?? null) === $wanted)) {
                $location = $candidate;
                break;
            }
        }
        $merchant = is_array($location) ? ($location['merchant_id'] ?? null) : null;
        $locationId = is_array($location) ? ($location['id'] ?? null) : null;
        if (! is_string($merchant) || $merchant === '' || ! is_string($locationId)) {
            throw new PaymentCredentialsRejected('That location is not on this Square account.');
        }
        $name = $location['business_name'] ?? $location['name'] ?? null;

        return new VerifiedAccount($merchant, is_string($name) && $name !== '' ? $name : null, $environment, $locationId);
    }

    public function registerWebhook(PaymentCredentials $credentials, string $url): ?RegisteredWebhook
    {
        // Square's subscription is registered by the owner, who then enters
        // the signature key it shows.
        return null;
    }

    public function removeWebhook(PaymentCredentials $credentials, string $endpointId): void {}
}
