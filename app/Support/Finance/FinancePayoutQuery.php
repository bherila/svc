<?php

namespace App\Support\Finance;

use App\Support\AgentApi\ChangeFeedPage;
use App\Support\AgentApi\ChangeWindow;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Which processor payouts `finance.payouts.list` returns (S7): optionally one
 * provider, one status and an arrival-date window, and a page of at most 100
 * rows (50 unless asked), newest arrival first.
 *
 * As on the payment listing ({@see FinancePaymentQuery}), naming
 * `updated_since`, `watermark` or `cursor` makes it one page of the change
 * feed ({@see ChangeFeedPage}), which takes no page number and only the filter
 * a payout never changes out of: its provider. A payout's status moves (paid,
 * then failed) and its arrival date can, so those filters are refused there.
 *
 * Built from the REST query - which the MCP tool reaches too - and from the
 * console options, so every door validates the same way.
 */
final readonly class FinancePayoutQuery
{
    public const int DEFAULT_PER_PAGE = 50;

    public const int MAX_PER_PAGE = 100;

    public const array PROVIDERS = ['stripe', 'square'];

    private function __construct(
        public ?string $provider,
        public ?PayoutStatus $status,
        public ?string $arrivalFrom,
        public ?string $arrivalTo,
        public int $perPage,
        public int $page,
        public ChangeWindow $window,
        public ?string $cursor,
    ) {}

    /** Whether this is a change-feed request rather than a numbered page. */
    public function changes(): bool
    {
        return $this->cursor !== null || $this->window->requested();
    }

    /** What the query selects, for binding a change-feed cursor to it. */
    public function key(): string
    {
        return json_encode([
            'provider' => $this->provider,
            'status' => $this->status?->value,
            'arrival_from' => $this->arrivalFrom,
            'arrival_to' => $this->arrivalTo,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function fromInput(array $input): self
    {
        $validated = Validator::make($input, [
            'provider' => ['sometimes', Rule::in(self::PROVIDERS)],
            'status' => ['sometimes', Rule::in(PayoutStatus::all())],
            'arrival_from' => ['sometimes', 'date_format:Y-m-d'],
            'arrival_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:arrival_from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'cursor' => ['sometimes', 'string', 'max:2048'],
            ...ChangeWindow::rules(),
        ])->validate();
        $window = ChangeWindow::from($validated);
        $cursor = isset($validated['cursor']) && $validated['cursor'] !== '' ? (string) $validated['cursor'] : null;
        if ($cursor !== null || $window->requested()) {
            if (array_key_exists('page', $input)) {
                throw ValidationException::withMessages(['page' => 'A change-feed request (updated_since, watermark or cursor) is followed by cursor, not page.']);
            }
            ChangeWindow::refuseMutableFilters([
                'provider' => isset($validated['provider']),
                'status' => isset($validated['status']),
                'arrival_from' => isset($validated['arrival_from']),
                'arrival_to' => isset($validated['arrival_to']),
            ], ['provider']);
        }
        $page = filter_var($input['page'] ?? null, FILTER_VALIDATE_INT);

        return new self(
            isset($validated['provider']) ? (string) $validated['provider'] : null,
            isset($validated['status']) ? PayoutStatus::from((string) $validated['status']) : null,
            isset($validated['arrival_from']) ? (string) $validated['arrival_from'] : null,
            isset($validated['arrival_to']) ? (string) $validated['arrival_to'] : null,
            (int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE),
            is_int($page) && $page >= 1 ? $page : 1,
            $window,
            $cursor,
        );
    }
}
