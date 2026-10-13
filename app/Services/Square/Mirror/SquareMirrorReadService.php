<?php

namespace App\Services\Square\Mirror;

use App\Models\AgentPrincipal;
use App\Models\CompanySquareCustomer;
use App\Models\SquareCustomerSnapshot;
use App\Models\SquareInvoiceSnapshot;
use App\Models\SquareLocationSnapshot;
use App\Models\SquareSyncRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Square\SquareClient;
use App\Services\Square\SquareConfiguration;
use App\Services\Square\SquareRequestFailed;
use App\Support\AgentApi\AgentApiCursor;
use App\Support\WorkspaceClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;

/**
 * Reads of svc's Square mirror for workspace managers (#438), shared by REST,
 * MCP and the operator's explore screen. Nothing here reaches Square: the
 * answers are as fresh as the last sync, and say when that was.
 */
final class SquareMirrorReadService
{
    public const int DEFAULT_LIMIT = 25;

    public const int MAX_INVOICE_LIMIT = 100;

    public const int MAX_SERIES_LIMIT = 50;

    /** Members returned with one series; a series this long is decades of monthly invoices. */
    public const int MAX_SERIES_MEMBERS = 600;

    /** Every status Square's Invoice enum defines. */
    public const array STATUSES = ['DRAFT', 'UNPAID', 'SCHEDULED', 'PARTIALLY_PAID', 'PAID', 'PARTIALLY_REFUNDED', 'REFUNDED', 'CANCELED', 'FAILED', 'PAYMENT_PENDING'];

    public const string SERIES_KEY = '/^\d{6}$/D';

    public const int DEFAULT_UNLINKED_LIMIT = 100;

    public const int MAX_UNLINKED_LIMIT = 500;

    public function __construct(
        private readonly AgentAccess $access,
        private readonly SquareMirrorPresenter $presenter,
        private readonly WorkspaceClock $clock,
    ) {}

    /**
     * The mirrored invoices matching the filters, in mirror order.
     *
     * @param  array<string, mixed>  $filters  location_id, status, customer_id, series_key, due_from, due_to
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function invoices(User|AgentPrincipal $actor, Workspace $workspace, array $filters, int $limit = self::DEFAULT_LIMIT, ?string $cursor = null): array
    {
        $filters = $this->filters($filters, ['limit' => $limit, 'cursor' => $cursor], self::MAX_INVOICE_LIMIT);
        $this->admit($actor, $workspace);
        $queryKey = 'square-mirror-invoices|'.json_encode($filters, JSON_THROW_ON_ERROR);
        $query = $this->invoiceQuery($workspace, $filters)->orderBy('id');
        $after = AgentApiCursor::decode($cursor, (string) $workspace->public_id, $queryKey);
        if ($after !== null) {
            $query->where('id', '>', $after);
        }
        $rows = $query->limit($limit + 1)->get();
        $next = null;
        if ($rows->count() > $limit) {
            $rows->pop();
            $last = $rows->last();
            $next = $last === null ? null : AgentApiCursor::encode((int) $last->getKey(), (string) $workspace->public_id, $queryKey);
        }

        return [
            'data' => array_values($rows->map($this->presenter->invoice(...))->all()),
            'meta' => ['next_cursor' => $next, ...$this->freshness($workspace)],
        ];
    }

    /**
     * One page of an explore screen: newest due first, numbered pages.
     *
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, page: int, last_page: int, total: int}
     */
    public function explorePage(User $actor, Workspace $workspace, array $filters, int $page, int $perPage = 50): array
    {
        $this->admit($actor, $workspace);
        $paginator = $this->invoiceQuery($workspace, $filters)
            ->orderByRaw('case when due_on is null then 1 else 0 end')
            ->orderByDesc('due_on')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', max(1, $page));

        return [
            'data' => array_values(array_map($this->presenter->invoice(...), $paginator->items())),
            'page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ];
    }

    /**
     * Recurring series, by series key.
     *
     * @param  array<string, mixed>  $filters  location_id, customer_id
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function series(User|AgentPrincipal $actor, Workspace $workspace, array $filters, int $limit = self::DEFAULT_LIMIT, ?string $cursor = null): array
    {
        $filters = $this->filters(array_intersect_key($filters, array_flip(['location_id', 'customer_id'])), ['limit' => $limit, 'cursor' => $cursor], self::MAX_SERIES_LIMIT);
        $this->admit($actor, $workspace);
        $queryKey = 'square-mirror-series|'.json_encode($filters, JSON_THROW_ON_ERROR);
        // A series key is six digits, so key + 1 is a positive cursor id.
        $after = AgentApiCursor::decode($cursor, (string) $workspace->public_id, $queryKey);
        $keys = self::keys($this->invoiceQuery($workspace, $filters)
            ->when($after !== null, static fn (Builder $query) => $query->where('series_key', '>', sprintf('%06d', $after - 1))), $limit + 1);
        $next = null;
        if (count($keys) > $limit) {
            array_pop($keys);
            $next = AgentApiCursor::encode((int) end($keys) + 1, (string) $workspace->public_id, $queryKey);
        }

        return [
            'data' => $this->summaries($workspace, $keys),
            'meta' => ['next_cursor' => $next, ...$this->freshness($workspace)],
        ];
    }

    /**
     * One series with every mirrored member, by occurrence.
     *
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function seriesDetail(User|AgentPrincipal $actor, Workspace $workspace, string $seriesKey): array
    {
        $this->validate(['series_key' => $seriesKey], ['series_key' => ['required', 'string', 'regex:'.self::SERIES_KEY]]);
        $this->admit($actor, $workspace);
        $members = $this->members($workspace, [$seriesKey])[$seriesKey] ?? [];
        abort_if($members === [], 404, 'The Square mirror has no series with that key.');
        $summary = $this->summary($workspace, $seriesKey, $members);
        $truncated = count($members) > self::MAX_SERIES_MEMBERS;
        $members = array_slice($members, 0, self::MAX_SERIES_MEMBERS);

        return [
            'data' => [
                ...$summary,
                'members' => array_map($this->presenter->invoice(...), $members),
                'members_truncated' => $truncated,
            ],
            'meta' => $this->freshness($workspace),
        ];
    }

    /**
     * Square customers with mirrored invoices and no client company mapped to
     * them, most owed first: receivables svc does not otherwise see.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function unlinkedCustomers(User|AgentPrincipal $actor, Workspace $workspace, ?bool $withOpenBalance = null, int $limit = self::DEFAULT_UNLINKED_LIMIT): array
    {
        $this->validate(['limit' => $limit], ['limit' => ['integer', 'min:1', 'max:'.self::MAX_UNLINKED_LIMIT]]);
        $this->admit($actor, $workspace);
        $mapped = CompanySquareCustomer::query()->where('workspace_id', $workspace->id)->whereNotNull('active_customer_slot')->select('active_customer_slot');
        $open = implode(',', array_map(static fn (string $status): string => "'{$status}'", SquareSeriesSummary::OPEN_STATUSES));
        $rows = SquareInvoiceSnapshot::query()->where('workspace_id', $workspace->id)
            ->whereNotNull('square_customer_id')
            ->whereNotIn('square_customer_id', $mapped)
            ->groupBy('square_customer_id')
            ->selectRaw("square_customer_id, count(*) as invoice_count, sum(case when status in ({$open}) then 1 else 0 end) as open_invoice_count, sum(case when status in ({$open}) then coalesce(total_amount, 0) - coalesce(paid_amount, 0) else 0 end) as open_amount, count(distinct case when status in ({$open}) then currency end) as currencies, max(case when status in ({$open}) then currency end) as currency, max(due_on) as last_due_on, max(recipient_name) as recipient_name")
            ->get();
        $customers = [];
        foreach ($rows as $row) {
            $openCount = (int) $row->getAttribute('open_invoice_count');
            if ($withOpenBalance !== null && ($openCount > 0) !== $withOpenBalance) {
                continue;
            }
            $multiCurrency = (int) $row->getAttribute('currencies') > 1;
            $customers[(string) $row->getAttribute('square_customer_id')] = [
                'invoice_count' => (int) $row->getAttribute('invoice_count'),
                'open_invoice_count' => $openCount,
                'open_amount' => $multiCurrency ? null : (int) $row->getAttribute('open_amount'),
                'currency' => $multiCurrency ? null : $row->getAttribute('currency'),
                'last_due_on' => self::day($row->getAttribute('last_due_on')),
                'recipient_name' => $row->getAttribute('recipient_name'),
            ];
        }
        $directory = SquareCustomerSnapshot::query()->where('workspace_id', $workspace->id)->whereIn('square_customer_id', array_map(strval(...), array_keys($customers)))->get()->keyBy('square_customer_id');
        $series = [];
        foreach (SquareInvoiceSnapshot::query()->where('workspace_id', $workspace->id)->whereIn('square_customer_id', array_map(strval(...), array_keys($customers)))
            ->whereNotNull('series_key')->distinct()->orderBy('series_key')->get(['square_customer_id', 'series_key']) as $member) {
            $series[(string) $member->square_customer_id][] = (string) $member->series_key;
        }
        $data = [];
        foreach ($customers as $id => $facts) {
            $id = (string) $id;
            $snapshot = $directory->get($id);
            $recipient = is_string($facts['recipient_name']) ? $facts['recipient_name'] : null;
            $data[] = [
                'customer_id' => $id,
                'display_name' => $snapshot instanceof SquareCustomerSnapshot ? ($snapshot->displayName() ?? $recipient) : $recipient,
                'company_name' => $snapshot?->company_name,
                'given_name' => $snapshot?->given_name,
                'family_name' => $snapshot?->family_name,
                'email_address' => $snapshot?->email_address,
                'in_customer_directory' => $snapshot !== null,
                'invoice_count' => $facts['invoice_count'],
                'open_invoice_count' => $facts['open_invoice_count'],
                'open_amount' => $facts['open_amount'],
                'currency' => is_string($facts['currency']) ? $facts['currency'] : null,
                'last_due_on' => $facts['last_due_on'],
                'series_keys' => array_slice($series[$id] ?? [], 0, 20),
            ];
        }
        usort($data, static fn (array $a, array $b): int => [$b['open_amount'] ?? -1, (string) $a['display_name'], $a['customer_id']] <=> [$a['open_amount'] ?? -1, (string) $b['display_name'], $b['customer_id']]);

        return [
            'data' => array_slice($data, 0, $limit),
            'meta' => ['truncated' => count($data) > $limit, ...$this->freshness($workspace)],
        ];
    }

    /**
     * When the mirror was last refreshed, and the latest run however it ended.
     *
     * @return array{last_synced_at: string|null, last_run: array<string, mixed>|null}
     */
    public function freshness(Workspace $workspace): array
    {
        $completed = SquareSyncRun::query()->where('workspace_id', $workspace->id)->where('status', SquareSyncRun::COMPLETED)
            ->orderByDesc('finished_at')->orderByDesc('id')->first();
        $latest = SquareSyncRun::query()->where('workspace_id', $workspace->id)->orderByDesc('id')->first();

        return [
            'last_synced_at' => SquareMirrorPresenter::instant($completed?->finished_at),
            'last_run' => $latest === null ? null : $this->presenter->run($latest),
        ];
    }

    /**
     * Validated filters, as the explore screen and the API take them.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $paging
     * @return array<string, string>
     */
    public function filters(array $filters, array $paging = [], int $maxLimit = self::MAX_INVOICE_LIMIT): array
    {
        $values = array_filter($filters, static fn (mixed $value): bool => $value !== null && $value !== '');
        $this->validate([...$values, ...$paging], [
            'location_id' => ['sometimes', 'string', 'regex:'.SquareClient::ID_PATTERN],
            'customer_id' => ['sometimes', 'string', 'regex:'.SquareClient::ID_PATTERN],
            'status' => ['sometimes', 'string', 'in:'.implode(',', self::STATUSES)],
            'series_key' => ['sometimes', 'string', 'regex:'.self::SERIES_KEY],
            'due_from' => ['sometimes', 'string', 'date_format:Y-m-d'],
            'due_to' => ['sometimes', 'string', 'date_format:Y-m-d', ...(isset($values['due_from']) ? ['after_or_equal:due_from'] : [])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.$maxLimit],
            'cursor' => ['nullable', 'string', 'max:2048'],
        ]);
        $known = array_intersect_key($values, array_flip(['location_id', 'customer_id', 'status', 'series_key', 'due_from', 'due_to']));
        ksort($known);

        return array_map(strval(...), $known);
    }

    /**
     * The explore screen's filter choices: what the mirror holds.
     *
     * @return array{locations: list<array{value: string, label: string}>, customers: list<array{value: string, label: string}>}
     */
    public function options(Workspace $workspace): array
    {
        $locations = [];
        foreach (SquareLocationSnapshot::query()->where('workspace_id', $workspace->id)->orderBy('name')->orderBy('id')->get() as $location) {
            $locations[] = ['value' => $location->square_location_id, 'label' => $location->name ?? $location->square_location_id];
        }
        $customers = [];
        foreach (SquareInvoiceSnapshot::query()->where('workspace_id', $workspace->id)->whereNotNull('square_customer_id')
            ->groupBy('square_customer_id')->selectRaw('square_customer_id, max(recipient_name) as recipient_name')->orderByRaw('max(recipient_name)')->get() as $customer) {
            $id = (string) $customer->getAttribute('square_customer_id');
            $name = $customer->getAttribute('recipient_name');
            $customers[] = ['value' => $id, 'label' => is_string($name) && $name !== '' ? $name : $id];
        }

        return ['locations' => $locations, 'customers' => $customers];
    }

    /**
     * Series summaries for the given keys, in that order.
     *
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    public function summaries(Workspace $workspace, array $keys): array
    {
        $members = $this->members($workspace, $keys);
        $summaries = [];
        foreach ($keys as $key) {
            $summaries[] = $this->summary($workspace, $key, $members[$key] ?? []);
        }

        return $summaries;
    }

    /**
     * Series keys in the mirror, at most $limit, in key order.
     *
     * @return list<string>
     */
    public function seriesKeys(Workspace $workspace, int $limit): array
    {
        return self::keys(SquareInvoiceSnapshot::query()->where('workspace_id', $workspace->id), $limit);
    }

    /**
     * @param  Builder<SquareInvoiceSnapshot>  $query
     * @return list<string>
     */
    private static function keys(Builder $query, int $limit): array
    {
        $keys = [];
        foreach ($query->whereNotNull('series_key')->distinct()->orderBy('series_key')->limit($limit)->pluck('series_key') as $key) {
            $keys[] = (string) $key;
        }

        return $keys;
    }

    /**
     * @param  list<SquareInvoiceSnapshot>  $members
     * @return array<string, mixed>
     */
    private function summary(Workspace $workspace, string $key, array $members): array
    {
        return SquareSeriesSummary::of($key, array_map(static fn (SquareInvoiceSnapshot $member): array => [
            'series_occurrence' => $member->series_occurrence,
            'due_on' => $member->due_on?->toDateString(),
            'scheduled_on' => $member->scheduled_at?->toDateString(),
            'status' => $member->status,
            'currency' => $member->currency,
            'total_amount' => $member->total_amount,
            'paid_amount' => $member->paid_amount,
            'autopay' => $member->autopay,
            'square_customer_id' => $member->square_customer_id,
            'recipient_name' => $member->recipient_name,
            'square_location_id' => $member->square_location_id,
        ], $members), $this->clock->today($workspace));
    }

    /**
     * Members of each series, by occurrence.
     *
     * @param  list<string>  $keys
     * @return array<string, list<SquareInvoiceSnapshot>>
     */
    private function members(Workspace $workspace, array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $rows = SquareInvoiceSnapshot::query()->where('workspace_id', $workspace->id)->whereIn('series_key', $keys)
            ->orderBy('series_key')->orderBy('series_occurrence')->orderBy('id')
            ->get();
        $members = [];
        foreach ($rows as $row) {
            $members[(string) $row->series_key][] = $row;
        }

        return $members;
    }

    /**
     * @param  array<string, string>  $filters
     * @return Builder<SquareInvoiceSnapshot>
     */
    private function invoiceQuery(Workspace $workspace, array $filters): Builder
    {
        $query = SquareInvoiceSnapshot::query()->where('workspace_id', $workspace->id);
        foreach (['location_id' => 'square_location_id', 'customer_id' => 'square_customer_id', 'status' => 'status', 'series_key' => 'series_key'] as $filter => $column) {
            if (isset($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }
        if (isset($filters['due_from'])) {
            $query->where('due_on', '>=', $filters['due_from']);
        }
        if (isset($filters['due_to'])) {
            $query->where('due_on', '<=', $filters['due_to']);
        }

        return $query;
    }

    private static function day(mixed $value): ?string
    {
        return is_string($value) && strlen($value) >= 10 ? substr($value, 0, 10) : null;
    }

    private function admit(User|AgentPrincipal $actor, Workspace $workspace): void
    {
        abort_unless($this->access->isWorkspaceManager($actor, $workspace), 403, 'The Square mirror is available to workspace managers only.');
        if (! SquareConfiguration::fromConfig()->servesWorkspace($workspace)) {
            throw SquareRequestFailed::notConnected();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rules
     */
    private function validate(array $data, array $rules): void
    {
        Validator::make($data, $rules)->validate();
    }
}
