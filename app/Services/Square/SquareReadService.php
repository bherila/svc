<?php

namespace App\Services\Square;

use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Validator;

/**
 * Read-only pass-through to Square for workspace managers (#438), shared by
 * the REST and MCP transports so both answer the same way.
 *
 * Each read checks, in order: the caller manages the workspace; Square is
 * connected to exactly this workspace; the workspace's per-minute budget of
 * Square reads is not spent. Inputs are validated and bounded here, the
 * response is reduced to {@see SquarePresenter}'s allow-listed shapes, and as
 * a last guard a response that would contain the credential is refused.
 * svc never changes anything in Square from here.
 */
final class SquareReadService
{
    public const int DEFAULT_LIMIT = 25;

    public const int MAX_CUSTOMER_LIMIT = 100;

    public const int MAX_INVOICE_LIMIT = 50;

    public const int MAX_PAYMENT_LIMIT = 50;

    /** Square's own ceiling on locations per seller; ListLocations is not paginated. */
    public const int MAX_LOCATIONS = 300;

    public const string RFC3339 = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,9})?(Z|[+-]\d{2}:\d{2})$/D';

    public function __construct(
        private readonly SquareClient $client,
        private readonly SquarePresenter $presenter,
        private readonly AgentAccess $access,
        private readonly RateLimiter $limiter,
    ) {}

    /** @return array{data: list<array<string, mixed>>} */
    public function locations(User|AgentPrincipal $actor, Workspace $workspace): array
    {
        $configuration = $this->admit($actor, $workspace);
        $response = $this->client->listLocations();

        return $this->deliver($configuration, [
            'data' => array_map($this->presenter->location(...), self::records($response, 'locations', self::MAX_LOCATIONS)),
        ]);
    }

    /** @return array{data: list<array<string, mixed>>, meta: array{next_cursor: string|null}} */
    public function customers(User|AgentPrincipal $actor, Workspace $workspace, int $limit = self::DEFAULT_LIMIT, ?string $cursor = null): array
    {
        $this->validate(compact('limit', 'cursor'), ['limit' => self::limitRule(self::MAX_CUSTOMER_LIMIT), 'cursor' => self::cursorRule()]);
        $configuration = $this->admit($actor, $workspace);
        $response = $this->client->listCustomers($cursor, $limit);

        return $this->page($configuration, $response, 'customers', self::MAX_CUSTOMER_LIMIT, $this->presenter->customer(...));
    }

    /**
     * Square searches customers by email address or phone number, exactly or
     * fuzzily; it has no name search.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: string|null}}
     */
    public function searchCustomers(User|AgentPrincipal $actor, Workspace $workspace, ?string $email, ?string $phone, string $match = 'fuzzy', int $limit = self::DEFAULT_LIMIT, ?string $cursor = null): array
    {
        $this->validate(compact('email', 'phone', 'match', 'limit', 'cursor'), [
            'email' => ['nullable', 'required_without:phone', 'string', 'max:254'],
            'phone' => ['nullable', 'required_without:email', 'string', 'max:32', 'regex:/^[0-9+().\- ]+$/D'],
            'match' => ['required', 'in:exact,fuzzy'],
            'limit' => self::limitRule(self::MAX_CUSTOMER_LIMIT),
            'cursor' => self::cursorRule(),
        ]);
        $configuration = $this->admit($actor, $workspace);
        $filter = [];
        if ($email !== null) {
            $filter['email_address'] = [$match => $email];
        }
        if ($phone !== null) {
            $filter['phone_number'] = [$match => $phone];
        }
        $response = $this->client->searchCustomers($filter, $cursor, $limit);

        return $this->page($configuration, $response, 'customers', self::MAX_CUSTOMER_LIMIT, $this->presenter->customer(...));
    }

    /** @return array{data: array<string, mixed>} */
    public function customer(User|AgentPrincipal $actor, Workspace $workspace, string $customerId): array
    {
        $this->validate(['customer_id' => $customerId], ['customer_id' => self::idRule()]);
        $configuration = $this->admit($actor, $workspace);

        return $this->deliver($configuration, ['data' => $this->presenter->customer(self::record($this->client->retrieveCustomer($customerId), 'customer'))]);
    }

    /** @return array{data: list<array<string, mixed>>, meta: array{next_cursor: string|null}} */
    public function invoices(User|AgentPrincipal $actor, Workspace $workspace, string $locationId, int $limit = self::DEFAULT_LIMIT, ?string $cursor = null): array
    {
        $this->validate(['location_id' => $locationId, 'limit' => $limit, 'cursor' => $cursor], ['location_id' => self::idRule(), 'limit' => self::limitRule(self::MAX_INVOICE_LIMIT), 'cursor' => self::cursorRule()]);
        $configuration = $this->admit($actor, $workspace);
        $response = $this->client->listInvoices($locationId, $cursor, $limit);

        return $this->page($configuration, $response, 'invoices', self::MAX_INVOICE_LIMIT, $this->presenter->invoice(...));
    }

    /**
     * Square searches one location's invoices, optionally for one customer.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: string|null}}
     */
    public function searchInvoices(User|AgentPrincipal $actor, Workspace $workspace, string $locationId, ?string $customerId = null, string $sortOrder = 'desc', int $limit = self::DEFAULT_LIMIT, ?string $cursor = null): array
    {
        $this->validate(['location_id' => $locationId, 'customer_id' => $customerId, 'sort_order' => $sortOrder, 'limit' => $limit, 'cursor' => $cursor], [
            'location_id' => self::idRule(),
            'customer_id' => ['nullable', 'string', 'regex:'.SquareClient::ID_PATTERN],
            'sort_order' => ['required', 'in:asc,desc'],
            'limit' => self::limitRule(self::MAX_INVOICE_LIMIT),
            'cursor' => self::cursorRule(),
        ]);
        $configuration = $this->admit($actor, $workspace);
        $response = $this->client->searchInvoices($locationId, $customerId, $sortOrder === 'desc', $cursor, $limit);

        return $this->page($configuration, $response, 'invoices', self::MAX_INVOICE_LIMIT, $this->presenter->invoice(...));
    }

    /** @return array{data: array<string, mixed>} */
    public function invoice(User|AgentPrincipal $actor, Workspace $workspace, string $invoiceId): array
    {
        $this->validate(['invoice_id' => $invoiceId], ['invoice_id' => self::idRule()]);
        $configuration = $this->admit($actor, $workspace);

        return $this->deliver($configuration, ['data' => $this->presenter->invoice(self::record($this->client->getInvoice($invoiceId), 'invoice'))]);
    }

    /** @return array{data: array<string, mixed>} */
    public function order(User|AgentPrincipal $actor, Workspace $workspace, string $orderId): array
    {
        $this->validate(['order_id' => $orderId], ['order_id' => self::idRule()]);
        $configuration = $this->admit($actor, $workspace);

        return $this->deliver($configuration, ['data' => $this->presenter->order(self::record($this->client->retrieveOrder($orderId), 'order'))]);
    }

    /** @return array{data: list<array<string, mixed>>, meta: array{next_cursor: string|null}} */
    public function payments(User|AgentPrincipal $actor, Workspace $workspace, ?string $beginTime = null, ?string $endTime = null, ?string $locationId = null, string $sortOrder = 'desc', int $limit = self::DEFAULT_LIMIT, ?string $cursor = null): array
    {
        $this->validate(['begin_time' => $beginTime, 'end_time' => $endTime, 'location_id' => $locationId, 'sort_order' => $sortOrder, 'limit' => $limit, 'cursor' => $cursor], [
            'begin_time' => ['nullable', 'string', 'regex:'.self::RFC3339, 'date'],
            'end_time' => ['nullable', 'string', 'regex:'.self::RFC3339, 'date'],
            'location_id' => ['nullable', 'string', 'regex:'.SquareClient::ID_PATTERN],
            'sort_order' => ['required', 'in:asc,desc'],
            'limit' => self::limitRule(self::MAX_PAYMENT_LIMIT),
            'cursor' => self::cursorRule(),
        ]);
        $configuration = $this->admit($actor, $workspace);
        $response = $this->client->listPayments($beginTime, $endTime, $locationId, $sortOrder === 'desc', $cursor, $limit);

        return $this->page($configuration, $response, 'payments', self::MAX_PAYMENT_LIMIT, $this->presenter->payment(...));
    }

    /** @return array{data: array<string, mixed>} */
    public function payment(User|AgentPrincipal $actor, Workspace $workspace, string $paymentId): array
    {
        $this->validate(['payment_id' => $paymentId], ['payment_id' => self::idRule()]);
        $configuration = $this->admit($actor, $workspace);

        return $this->deliver($configuration, ['data' => $this->presenter->payment(self::record($this->client->getPayment($paymentId), 'payment'))]);
    }

    /**
     * The caller manages the workspace, Square is connected to it, and its
     * budget of Square reads allows one more.
     */
    private function admit(User|AgentPrincipal $actor, Workspace $workspace): SquareConfiguration
    {
        abort_unless($this->access->isWorkspaceManager($actor, $workspace), 403, 'Square data is available to workspace managers only.');
        $configuration = SquareConfiguration::fromConfig();
        if (! $configuration->servesWorkspace($workspace)) {
            throw SquareRequestFailed::notConnected();
        }
        $key = 'square-reads:'.$workspace->getKey();
        if (! $this->limiter->attempt($key, $configuration->rateLimitPerMinute, static fn (): bool => true, 60)) {
            throw SquareRequestFailed::rateLimited($this->limiter->availableIn($key));
        }

        return $configuration;
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  callable(array<mixed>): array<string, mixed>  $present
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: string|null}}
     */
    private function page(SquareConfiguration $configuration, array $response, string $key, int $max, callable $present): array
    {
        $cursor = $response['cursor'] ?? null;
        if ($cursor !== null && (! is_string($cursor) || preg_match(SquareClient::CURSOR_PATTERN, $cursor) !== 1)) {
            throw SquareRequestFailed::unreadable();
        }

        return $this->deliver($configuration, [
            'data' => array_map($present, self::records($response, $key, $max)),
            'meta' => ['next_cursor' => $cursor],
        ]);
    }

    /**
     * @template T of array
     *
     * @param  T  $result
     * @return T
     */
    private function deliver(SquareConfiguration $configuration, array $result): array
    {
        if ($configuration->credentialAppearsIn(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))) {
            throw SquareRequestFailed::unreadable();
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<mixed>>
     */
    private static function records(array $response, string $key, int $max): array
    {
        // Square omits an empty list rather than sending [].
        $records = $response[$key] ?? [];
        if (! is_array($records) || ! array_is_list($records)) {
            throw SquareRequestFailed::unreadable();
        }
        $records = array_values(array_filter($records, 'is_array'));
        // A cursor resumes after Square's whole page, so records past the cap
        // could never be reached: refuse the page rather than drop them.
        if (count($records) > $max) {
            throw SquareRequestFailed::unreadable();
        }

        return $records;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<mixed>
     */
    private static function record(array $response, string $key): array
    {
        $record = $response[$key] ?? null;
        if (! is_array($record)) {
            throw SquareRequestFailed::unreadable();
        }

        return $record;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rules
     */
    private function validate(array $data, array $rules): void
    {
        Validator::make($data, $rules)->validate();
    }

    /** @return list<string> */
    private static function idRule(): array
    {
        return ['required', 'string', 'regex:'.SquareClient::ID_PATTERN];
    }

    /** @return list<string> */
    private static function cursorRule(): array
    {
        return ['nullable', 'string', 'max:2048', 'regex:'.SquareClient::CURSOR_PATTERN];
    }

    /** @return list<string> */
    private static function limitRule(int $max): array
    {
        return ['required', 'integer', 'min:1', 'max:'.$max];
    }
}
