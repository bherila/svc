<?php

namespace App\Services\Square;

use App\Support\Square\SquareIdentifiers;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use InvalidArgumentException;

/**
 * Read-only Square client (#438).
 *
 * Every request is built from a {@see SquareEndpoint} case, the allow-list of
 * reads, and nothing else: there is no generic request method and no write.
 * Each call re-reads {@see SquareConfiguration}, sends the pinned
 * Square-Version, uses bounded timeouts, never follows a redirect (which could
 * carry the credential to another host), and retries only these reads - on a
 * connection failure, 429 or 5xx - with doubling backoff. A failure becomes a
 * {@see SquareRequestFailed} that carries Square's error category and code and
 * nothing from the request.
 */
final class SquareClient
{
    /** Non-invoice Square ids are opaque: letters, digits, and `:`, `_` or `-` (e.g. `inv:0-...`). */
    public const string ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9:_\-]{0,191}$/D';

    /** Square cursors are opaque, printable and unspaced. */
    public const string CURSOR_PATTERN = '/^[\x21-\x7E]{1,2048}$/D';

    /** The longest pause between attempts; a longer Retry-After is answered as 429 at once. */
    private const int MAX_PAUSE_MILLISECONDS = 2000;

    public function __construct(private readonly HttpFactory $http) {}

    /** @return array<string, mixed> */
    public function listLocations(): array
    {
        return $this->send(SquareEndpoint::ListLocations);
    }

    /** @return array<string, mixed> */
    public function listCustomers(?string $cursor, int $limit): array
    {
        return $this->send(SquareEndpoint::ListCustomers, query: [
            'cursor' => $this->cursor($cursor),
            'limit' => self::limit($limit, 100),
            'sort_field' => 'DEFAULT',
            'sort_order' => 'ASC',
        ]);
    }

    /**
     * @param  array<string, array{exact?: string, fuzzy?: string}>  $filter  email_address and/or phone_number text filters
     * @return array<string, mixed>
     */
    public function searchCustomers(array $filter, ?string $cursor, int $limit): array
    {
        $allowed = array_intersect_key($filter, array_flip(['email_address', 'phone_number']));
        if ($allowed === [] || count($allowed) !== count($filter)) {
            throw new InvalidArgumentException('A customer search filters by email address or phone number.');
        }

        return $this->send(SquareEndpoint::SearchCustomers, body: array_filter([
            'cursor' => $this->cursor($cursor),
            'limit' => self::limit($limit, 100),
            'query' => ['filter' => $allowed],
        ], static fn (mixed $value): bool => $value !== null));
    }

    /** @return array<string, mixed> */
    public function retrieveCustomer(string $customerId): array
    {
        return $this->send(SquareEndpoint::RetrieveCustomer, $customerId);
    }

    /** @return array<string, mixed> */
    public function listInvoices(string $locationId, ?string $cursor, int $limit): array
    {
        return $this->send(SquareEndpoint::ListInvoices, query: [
            'location_id' => $this->id($locationId),
            'cursor' => $this->cursor($cursor),
            'limit' => self::limit($limit, 200),
        ]);
    }

    /** @return array<string, mixed> */
    public function searchInvoices(string $locationId, ?string $customerId, bool $newestFirst, ?string $cursor, int $limit): array
    {
        $filter = ['location_ids' => [$this->id($locationId)]];
        if ($customerId !== null) {
            $filter['customer_ids'] = [$this->id($customerId)];
        }

        return $this->send(SquareEndpoint::SearchInvoices, body: array_filter([
            'cursor' => $this->cursor($cursor),
            'limit' => self::limit($limit, 200),
            'query' => ['filter' => $filter, 'sort' => ['field' => 'INVOICE_SORT_DATE', 'order' => $newestFirst ? 'DESC' : 'ASC']],
        ], static fn (mixed $value): bool => $value !== null));
    }

    /** @return array<string, mixed> */
    public function getInvoice(string $invoiceId): array
    {
        return $this->send(SquareEndpoint::GetInvoice, $invoiceId);
    }

    /** @return array<string, mixed> */
    public function retrieveOrder(string $orderId): array
    {
        return $this->send(SquareEndpoint::RetrieveOrder, $orderId);
    }

    /**
     * @param  string|null  $beginTime  RFC 3339
     * @param  string|null  $endTime  RFC 3339
     * @return array<string, mixed>
     */
    public function listPayments(?string $beginTime, ?string $endTime, ?string $locationId, bool $newestFirst, ?string $cursor, int $limit): array
    {
        return $this->send(SquareEndpoint::ListPayments, query: [
            'begin_time' => $beginTime,
            'end_time' => $endTime,
            'location_id' => $locationId === null ? null : $this->id($locationId),
            'sort_order' => $newestFirst ? 'DESC' : 'ASC',
            'cursor' => $this->cursor($cursor),
            'limit' => self::limit($limit, 100),
        ]);
    }

    /** @return array<string, mixed> */
    public function getPayment(string $paymentId): array
    {
        return $this->send(SquareEndpoint::GetPayment, $paymentId);
    }

    /**
     * All states and locations: wins must be recovered as well as new disputes.
     *
     * @return array<string, mixed>
     */
    public function listDisputes(?string $cursor): array
    {
        return $this->send(SquareEndpoint::ListDisputes, query: ['cursor' => $this->cursor($cursor)]);
    }

    /** @return array<string, mixed> */
    public function getDispute(string $disputeId): array
    {
        return $this->send(SquareEndpoint::RetrieveDispute, $disputeId);
    }

    /**
     * The actual refund named by a balance entry, without issuing a refund.
     * https://developer.squareup.com/reference/square/refunds-api/get-payment-refund
     *
     * @return array<string, mixed>
     */
    public function getPaymentRefund(string $refundId): array
    {
        return $this->send(SquareEndpoint::GetPaymentRefund, $refundId);
    }

    /**
     * The seller's payouts for one location, newest first by default (S7).
     *
     * @param  string|null  $beginTime  RFC 3339
     * @return array<string, mixed>
     */
    public function listPayouts(?string $locationId, ?string $beginTime, ?string $cursor, int $limit, ?string $endTime = null, bool $newestFirst = true): array
    {
        return $this->send(SquareEndpoint::ListPayouts, query: [
            'location_id' => $locationId === null ? null : $this->id($locationId),
            'begin_time' => $beginTime,
            'end_time' => $endTime,
            'sort_order' => $newestFirst ? 'DESC' : 'ASC',
            'cursor' => $this->cursor($cursor),
            'limit' => self::limit($limit, 100),
        ]);
    }

    /** @return array<string, mixed> */
    public function getPayout(string $payoutId): array
    {
        return $this->send(SquareEndpoint::GetPayout, $payoutId);
    }

    /**
     * What one payout settled: its charges, refunds, fees and adjustments.
     *
     * @return array<string, mixed>
     */
    public function listPayoutEntries(string $payoutId, ?string $cursor, int $limit): array
    {
        return $this->send(SquareEndpoint::ListPayoutEntries, $payoutId, query: [
            'sort_order' => 'ASC',
            'cursor' => $this->cursor($cursor),
            'limit' => self::limit($limit, 100),
        ]);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function send(SquareEndpoint $endpoint, ?string $id = null, array $query = [], ?array $body = null): array
    {
        $configuration = SquareConfiguration::fromConfig();
        if (! $configuration->isConfigured()) {
            throw SquareRequestFailed::notConnected();
        }
        if ($endpoint->takesId() !== ($id !== null)) {
            throw new InvalidArgumentException('This Square endpoint takes exactly one id.');
        }
        $path = $id === null ? $endpoint->path() : str_replace('{id}', rawurlencode($endpoint === SquareEndpoint::GetInvoice ? $this->invoiceId($id) : $this->id($id)), $endpoint->path());
        $query = array_filter($query, static fn (mixed $value): bool => $value !== null);

        for ($attempt = 1; ; $attempt++) {
            $request = $this->http
                ->baseUrl($configuration->baseUrl())
                ->withToken($configuration->accessToken())
                ->withHeaders(['Square-Version' => $configuration->version])
                ->acceptJson()
                ->withoutRedirecting()
                ->timeout($configuration->timeoutSeconds)
                ->connectTimeout($configuration->connectTimeoutSeconds);

            try {
                $response = $endpoint->method() === 'POST'
                    ? $request->asJson()->post($path, $body ?? [])
                    : $request->get($path, $query);
            } catch (ConnectionException|TransferException) {
                // Deliberately not chained: a transport message names the URL.
                if ($attempt < $configuration->maxAttempts && $this->pause($configuration, $attempt, null)) {
                    continue;
                }

                throw SquareRequestFailed::unavailable(SquareReadFailureReason::Transport);
            }

            if ($response->successful()) {
                $decoded = $response->json();
                if (! is_array($decoded)) {
                    throw SquareRequestFailed::unreadable(SquareReadFailureReason::MalformedJson);
                }

                return $decoded;
            }
            $retryable = $response->status() === 429 || $response->serverError();
            if ($retryable && $attempt < $configuration->maxAttempts && $this->pause($configuration, $attempt, $response)) {
                continue;
            }

            throw self::failure($response);
        }
    }

    /** Waits before another attempt, or returns false when Square asked for longer than svc will wait. */
    private function pause(SquareConfiguration $configuration, int $attempt, ?Response $response): bool
    {
        $milliseconds = $configuration->retryBaseMilliseconds * (2 ** ($attempt - 1));
        $retryAfter = $response?->header('Retry-After');
        if (is_string($retryAfter) && ctype_digit($retryAfter)) {
            $milliseconds = max($milliseconds, (int) $retryAfter * 1000);
        }
        if ($milliseconds > self::MAX_PAUSE_MILLISECONDS) {
            return false;
        }
        Sleep::for($milliseconds)->milliseconds();

        return true;
    }

    private static function failure(Response $response): SquareRequestFailed
    {
        $error = $response->json('errors.0');
        $category = is_array($error) && is_string($error['category'] ?? null) ? $error['category'] : null;
        $code = is_array($error) && is_string($error['code'] ?? null) ? $error['code'] : null;
        $status = $response->status();

        if ($status === 429) {
            $retryAfter = $response->header('Retry-After');

            return SquareRequestFailed::rateLimitedBySquare(ctype_digit($retryAfter) ? (int) $retryAfter : 30);
        }
        if ($status >= 500) {
            return SquareRequestFailed::unavailable();
        }
        if ($status === 401 || $status === 403) {
            return SquareRequestFailed::credentialRefused($category, $code);
        }
        if ($status === 404) {
            return SquareRequestFailed::notFound();
        }
        if ($status >= 400) {
            return SquareRequestFailed::rejected($category, $code);
        }

        // A redirect (never followed) or anything else Square does not send.
        return SquareRequestFailed::unreadable();
    }

    private function invoiceId(string $id): string
    {
        if (! SquareIdentifiers::isInvoiceId($id)) {
            throw new InvalidArgumentException('Not a Square invoice id.');
        }

        return $id;
    }

    private function id(string $id): string
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException('Not a Square id.');
        }

        return $id;
    }

    private function cursor(?string $cursor): ?string
    {
        if ($cursor !== null && preg_match(self::CURSOR_PATTERN, $cursor) !== 1) {
            throw new InvalidArgumentException('Not a Square cursor.');
        }

        return $cursor;
    }

    private static function limit(int $limit, int $max): int
    {
        return max(1, min($max, $limit));
    }
}
