<?php

namespace Tests\Concerns;

use Closure;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;

/**
 * Answers the Stripe SDK's HTTP requests in-process, so nothing reaches
 * Stripe. Every request is recorded in {@see $stripeCalls}. The answer closure
 * receives the method, the path and the parameters and returns the decoded
 * body, optionally with a status. Every id and amount is synthetic.
 */
trait FakesStripeApi
{
    /** @var list<array{method: string, path: string, params: array<string, mixed>}> */
    protected array $stripeCalls = [];

    /** @param Closure(string, string, array<string, mixed>): array{0: array<string, mixed>, 1?: int} $answer */
    protected function fakeStripeApi(Closure $answer): void
    {
        config(['services.stripe.secret_key' => 'sk_test_synthetic']);
        $record = function (string $method, string $path, array $params): void {
            $this->stripeCalls[] = ['method' => $method, 'path' => $path, 'params' => $params];
        };
        ApiRequestor::setHttpClient(new class($answer, $record) implements ClientInterface
        {
            public function __construct(private readonly Closure $answer, private readonly Closure $record) {}

            /**
             * @param  array<int|string, mixed>  $headers
             * @param  array<string, mixed>  $params
             * @return array{0: string, 1: int, 2: array<string, string>}
             */
            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $path = (string) parse_url($absUrl, PHP_URL_PATH);
                ($this->record)((string) $method, $path, $params);
                $answer = ($this->answer)((string) $method, $path, $params);

                return [json_encode($answer[0], JSON_THROW_ON_ERROR), $answer[1] ?? 200, []];
            }
        });
    }

    protected function restoreStripeApi(): void
    {
        ApiRequestor::setHttpClient(CurlClient::instance());
    }

    /**
     * @param  list<array<string, mixed>>  $data
     * @return array<string, mixed>
     */
    protected static function stripeList(string $url, array $data): array
    {
        return ['object' => 'list', 'url' => $url, 'has_more' => false, 'data' => $data];
    }

    /** @return array{0: array<string, mixed>, 1: int} */
    protected static function stripeError(int $status = 500): array
    {
        return [['error' => ['type' => 'api_error', 'message' => 'Synthetic Stripe failure.']], $status];
    }

    /**
     * A charge's balance transaction in a payout, with its source expanded.
     *
     * @return array<string, mixed>
     */
    protected static function stripeChargeEntry(string $charge, string $paymentIntent, int $amount, int $fee, int $created = 1_760_000_000, string $currency = 'usd'): array
    {
        return [
            'id' => 'txn_'.$charge, 'object' => 'balance_transaction', 'type' => 'charge', 'amount' => $amount, 'fee' => $fee,
            'net' => $amount - $fee, 'currency' => $currency, 'created' => $created + 5,
            'source' => ['id' => $charge, 'object' => 'charge', 'payment_intent' => $paymentIntent, 'created' => $created],
        ];
    }

    /**
     * A refund's balance transaction in a payout: it names the payment it
     * pays back but settles nothing on it.
     *
     * @return array<string, mixed>
     */
    protected static function stripeRefundEntry(string $refund, string $charge, string $paymentIntent, int $amount): array
    {
        return [
            'id' => 'txn_'.$refund, 'object' => 'balance_transaction', 'type' => 'refund', 'amount' => -$amount, 'fee' => 0,
            'net' => -$amount, 'currency' => 'usd', 'created' => 1_760_100_000,
            'source' => ['id' => $refund, 'object' => 'refund', 'charge' => $charge, 'payment_intent' => $paymentIntent],
        ];
    }

    /**
     * A Stripe payout object.
     *
     * @return array<string, mixed>
     */
    protected static function stripePayout(string $id, int $amount, string $status = 'paid', bool $automatic = true, string $method = 'standard', int $arrival = 1_760_918_400): array
    {
        return [
            'id' => $id, 'object' => 'payout', 'amount' => $amount, 'currency' => 'usd', 'arrival_date' => $arrival,
            'status' => $status, 'automatic' => $automatic, 'method' => $method, 'type' => 'bank_account',
        ];
    }
}
