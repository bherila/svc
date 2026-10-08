<?php

namespace App\Support\AgentApi;

use App\Support\Billing\InvoiceKind;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Validated facts used by both transports; no queries or model lookups. */
final readonly class InvoiceListFilters
{
    /** @param array<string, mixed> $values */
    private function __construct(public array $values) {}

    /** @param array<string, mixed> $input */
    public static function from(array $input): self
    {
        foreach (['collectible', 'overdue'] as $field) {
            if (array_key_exists($field, $input)) {
                $normalized = filter_var($input[$field], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($normalized !== null) {
                    $input[$field] = $normalized;
                }
            }
        }

        return new self(Validator::make($input, self::rules())->validate());
    }

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'company_id' => ['sometimes', 'uuid'],
            'invoice_kind' => ['sometimes', Rule::enum(InvoiceKind::class)],
            'issue_date_from' => ['sometimes', 'date_format:Y-m-d'],
            'issue_date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:issue_date_from'],
            'due_date_from' => ['sometimes', 'date_format:Y-m-d'],
            'due_date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:due_date_from'],
            'service_period_overlaps' => ['sometimes', 'array:from,to'],
            'service_period_overlaps.from' => ['required_with:service_period_overlaps', 'date_format:Y-m-d'],
            'service_period_overlaps.to' => ['required_with:service_period_overlaps', 'date_format:Y-m-d', 'after_or_equal:service_period_overlaps.from'],
            'collectible' => ['sometimes', 'boolean'],
            'overdue' => ['sometimes', 'boolean'],
        ];
    }
}
