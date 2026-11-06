<?php

namespace Tests\Concerns;

use Closure;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;

/**
 * Answers the Stripe SDK in-process and records which key made each request, so
 * a test can prove a call carried one workspace's key and not another's. The
 * answer closure receives the method, path, params and the key used. Nothing
 * reaches Stripe; every id and key is synthetic.
 */
trait FakesStripeKeys
{
    /** @var list<array{method: string, path: string, params: array<string, mixed>, key: string}> */
    protected array $keyedStripeCalls = [];

    /** @param Closure(string, string, array<string, mixed>, string): array{0: array<string, mixed>, 1?: int} $answer */
    protected function fakeStripeKeys(Closure $answer): void
    {
        ApiRequestor::setHttpClient(new class($answer, function (array $call): void {
            $this->keyedStripeCalls[] = $call;
        }) implements ClientInterface
        {

            public function __construct(private readonly Closure $answer, private readonly Closure $record) {}

            /**
             * @param  array<int|string, mixed>  $headers
             * @param  array<string, mixed>  $params
             * @return array{0: string, 1: int, 2: array<string, string>}
             */
            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $key = '';
                foreach ($headers as $header) {
                    if (is_string($header) && str_starts_with($header, 'Authorization: Bearer ')) {
                        $key = substr($header, strlen('Authorization: Bearer '));
                    }
                }
                $path = (string) parse_url($absUrl, PHP_URL_PATH);
                ($this->record)(['method' => (string) $method, 'path' => $path, 'params' => $params, 'key' => $key]);
                $answer = ($this->answer)((string) $method, $path, $params, $key);

                return [json_encode($answer[0], JSON_THROW_ON_ERROR), $answer[1] ?? 200, []];
            }
        });
    }

    protected function restoreStripeKeys(): void
    {
        ApiRequestor::setHttpClient(CurlClient::instance());
    }

    /**
     * A standard answer: the account read, webhook endpoint create and delete,
     * and payment intent create. The account id is derived from the key, so two
     * workspaces' keys open two different accounts.
     *
     * @return Closure(string, string, array<string, mixed>, string): array{0: array<string, mixed>, 1?: int}
     */
    protected static function stripeAnswers(): Closure
    {
        return static function (string $method, string $path, array $params, string $key): array {
            if (str_contains($key, 'rejected')) {
                return [['error' => ['type' => 'authentication_error', 'message' => 'Invalid API Key.']], 401];
            }
            $suffix = substr(hash('sha256', $key), 0, 8);

            return match (true) {
                $path === '/v1/account' => [['id' => 'acct_'.$suffix, 'object' => 'account', 'business_profile' => ['name' => 'Synthetic Business '.$suffix]]],
                $path === '/v1/webhook_endpoints' && $method === 'post' => [['id' => 'we_'.$suffix, 'object' => 'webhook_endpoint', 'secret' => 'whsec_'.$suffix, 'url' => (string) ($params['url'] ?? '')]],
                str_starts_with($path, '/v1/webhook_endpoints/') && $method === 'delete' => [['id' => basename($path), 'object' => 'webhook_endpoint', 'deleted' => true]],
                $path === '/v1/payment_intents' && $method === 'post' => [['id' => 'pi_'.$suffix.'_'.substr(hash('sha256', (string) ($params['description'] ?? '')), 0, 6), 'object' => 'payment_intent', 'client_secret' => 'pi_secret_synthetic']],
                default => [['error' => ['type' => 'invalid_request_error', 'message' => 'Unhandled synthetic path '.$path]], 404],
            };
        };
    }
}
