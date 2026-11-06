<?php

declare(strict_types=1);

namespace App\Support\Accounting;

use App\Support\AgentApi\ChangeWindow;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * What one accounting feed read selects, validated once for every door (the
 * REST route and the MCP tool that reaches it): an entry type, one client or
 * one invoice, a window of effective dates, a page of at most 100 entries (50
 * unless asked), and the change-feed window and cursor.
 *
 * Always a cursor listing, newest entry first inside a snapshot watermark;
 * there is no page number. The `from` and `to` dates are bounds on the date
 * the entry takes effect on - an invoice's issue date, the day a correction,
 * void or refund was recorded in the workspace's calendar, a payment's
 * received date, a movement's occurrence date. Where a date can still change
 * (the cash feed) they are refused beside `updated_since` or `watermark`.
 */
final readonly class AccountingFeedQuery
{
    public const int DEFAULT_PER_PAGE = 50;

    public const int MAX_PER_PAGE = 100;

    private function __construct(
        public AccountingFeed $feed,
        public ?string $entryType,
        public ?string $clientId,
        public ?string $invoiceId,
        public ?string $from,
        public ?string $to,
        public int $perPage,
        public ChangeWindow $window,
        public ?string $cursor,
    ) {}

    /** What the query selects, for binding a cursor to it. */
    public function key(): string
    {
        return json_encode([
            $this->feed->value, $this->entryType, $this->clientId, $this->invoiceId, $this->from, $this->to,
        ], JSON_THROW_ON_ERROR);
    }

    /** Whether the feed includes entries of this type. */
    public function wants(string $entryType): bool
    {
        return $this->entryType === null || $this->entryType === $entryType;
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function fromInput(AccountingFeed $feed, array $input): self
    {
        $rules = [
            'entry_type' => ['sometimes', 'string', Rule::in($feed->entryTypes())],
            'client_id' => ['sometimes', 'string', 'uuid'],
            'invoice_id' => ['sometimes', 'string', 'uuid'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', ...(isset($input['from']) ? ['after_or_equal:from'] : [])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'cursor' => ['sometimes', 'string', 'max:2048'],
            ...ChangeWindow::rules(),
        ];
        $unknown = array_diff(array_keys($input), array_keys($rules));
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, 'This filter is not supported by this accounting feed.'));
        }
        $validated = Validator::make($input, $rules)->validate();
        $window = ChangeWindow::from($validated);
        if ($feed->datesCanChange() && $window->requested()) {
            ChangeWindow::refuseMutableFilters([
                'from' => isset($validated['from']),
                'to' => isset($validated['to']),
            ], []);
        }

        return new self(
            $feed,
            $validated['entry_type'] ?? null,
            $validated['client_id'] ?? null,
            $validated['invoice_id'] ?? null,
            $validated['from'] ?? null,
            $validated['to'] ?? null,
            (int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE),
            $window,
            isset($validated['cursor']) && $validated['cursor'] !== '' ? $validated['cursor'] : null,
        );
    }
}
