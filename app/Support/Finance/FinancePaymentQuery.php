<?php

namespace App\Support\Finance;

use App\Support\AgentApi\ChangeFeedPage;
use App\Support\AgentApi\ChangeWindow;
use App\Support\Billing\InvoicePaymentStatus;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Which received payments `finance.invoice_payments.list` returns (#457):
 * one status (succeeded unless asked; `any` for every status), optionally one
 * invoice and a received-on window, and a page of at most 100 rows (50
 * unless asked).
 *
 * Numbered pages unless the request names `updated_since`, `watermark` or
 * `cursor`: then it is one page of the incremental change feed
 * ({@see ChangeFeedPage}), which a synchronizer follows by cursor and which
 * takes no page number.
 *
 * Built from the REST query - which the MCP tool reaches too - and from the
 * console options, so every door validates the same way.
 */
final readonly class FinancePaymentQuery
{
    public const int DEFAULT_PER_PAGE = 50;

    public const int MAX_PER_PAGE = 100;

    /** Every status: a full refund moves a payment to refunded while a partial one stays succeeded. */
    public const string ANY_STATUS = 'any';

    private function __construct(
        /** Null for every status. */
        public ?InvoicePaymentStatus $status,
        public ?string $invoicePublicId,
        public ?string $receivedFrom,
        public ?string $receivedTo,
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
            'status' => $this->status->value ?? self::ANY_STATUS,
            'invoice' => $this->invoicePublicId,
            'received_from' => $this->receivedFrom,
            'received_to' => $this->receivedTo,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * The fields as a query string carries them. An unusable `page` reads as
     * the first page, as Laravel's paginator has always read it here.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function fromInput(array $input): self
    {
        $validated = Validator::make($input, [
            'status' => ['sometimes', Rule::in([...InvoicePaymentStatus::all(), self::ANY_STATUS])],
            'invoice' => ['sometimes', 'uuid'],
            'received_from' => ['sometimes', 'date_format:Y-m-d'],
            'received_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:received_from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'cursor' => ['sometimes', 'string', 'max:2048'],
            ...ChangeWindow::rules(),
        ])->validate();
        $window = ChangeWindow::from($validated);
        $cursor = isset($validated['cursor']) && $validated['cursor'] !== '' ? (string) $validated['cursor'] : null;
        if ($cursor !== null || $window->requested()) {
            // A change feed is followed by cursor; a page number there would be ignored, so it is refused.
            if (array_key_exists('page', $input)) {
                throw ValidationException::withMessages(['page' => 'A change-feed request (updated_since, watermark or cursor) is followed by cursor, not page.']);
            }
            // A payment's status and received-on date change - a refund, a
            // dispute, a corrected date - so a feed filtered on either would
            // never deliver the change that took a payment out of it. The feed
            // reads every status, said explicitly.
            if (($validated['status'] ?? null) !== self::ANY_STATUS) {
                throw ValidationException::withMessages(['status' => 'A change-feed request (updated_since, watermark or cursor) reads every status: send status=any and filter the synced payments.']);
            }
            ChangeWindow::refuseMutableFilters([
                'received_from' => isset($validated['received_from']),
                'received_to' => isset($validated['received_to']),
                'invoice' => isset($validated['invoice']),
            ], ['invoice']);
        }
        $page = filter_var($input['page'] ?? null, FILTER_VALIDATE_INT);
        $status = (string) ($validated['status'] ?? InvoicePaymentStatus::Succeeded->value);

        return new self(
            $status === self::ANY_STATUS ? null : InvoicePaymentStatus::from($status),
            isset($validated['invoice']) ? (string) $validated['invoice'] : null,
            isset($validated['received_from']) ? (string) $validated['received_from'] : null,
            isset($validated['received_to']) ? (string) $validated['received_to'] : null,
            (int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE),
            is_int($page) && $page >= 1 ? $page : 1,
            $window,
            $cursor,
        );
    }
}
