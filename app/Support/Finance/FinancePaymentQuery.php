<?php

namespace App\Support\Finance;

use App\Support\Billing\InvoicePaymentStatus;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Which received payments `finance.invoice_payments.list` returns (#457):
 * one status (succeeded unless asked), optionally one invoice and a
 * received-on window, and a page of at most 100 rows (50 unless asked).
 *
 * Built from the REST query - which the MCP tool reaches too - and from the
 * console options, so every door validates the same way.
 */
final readonly class FinancePaymentQuery
{
    public const int DEFAULT_PER_PAGE = 50;

    public const int MAX_PER_PAGE = 100;

    private function __construct(
        public InvoicePaymentStatus $status,
        public ?string $invoicePublicId,
        public ?string $receivedFrom,
        public ?string $receivedTo,
        public int $perPage,
        public int $page,
    ) {}

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
            'status' => ['sometimes', Rule::in(InvoicePaymentStatus::all())],
            'invoice' => ['sometimes', 'uuid'],
            'received_from' => ['sometimes', 'date_format:Y-m-d'],
            'received_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:received_from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ])->validate();
        $page = filter_var($input['page'] ?? null, FILTER_VALIDATE_INT);

        return new self(
            InvoicePaymentStatus::from((string) ($validated['status'] ?? InvoicePaymentStatus::Succeeded->value)),
            isset($validated['invoice']) ? (string) $validated['invoice'] : null,
            isset($validated['received_from']) ? (string) $validated['received_from'] : null,
            isset($validated['received_to']) ? (string) $validated['received_to'] : null,
            (int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE),
            is_int($page) && $page >= 1 ? $page : 1,
        );
    }
}
