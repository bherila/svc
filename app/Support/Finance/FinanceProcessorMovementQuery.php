<?php

namespace App\Support\Finance;

use App\Support\AgentApi\ChangeWindow;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Immutable filters shared by every door to the processor-movement change feed. */
final readonly class FinanceProcessorMovementQuery
{
    public const int DEFAULT_PER_PAGE = 50;

    public const int MAX_PER_PAGE = 100;

    public const array PROVIDERS = ['stripe', 'square'];

    public const array KINDS = ['refund', 'refund_reversal', 'dispute_debit', 'dispute_credit', 'fee', 'fee_reversal'];

    private function __construct(
        public ?string $provider,
        public ?string $kind,
        public ?string $paymentId,
        public ?string $occurredFrom,
        public ?string $occurredTo,
        public int $perPage,
        public ChangeWindow $window,
        public ?string $cursor,
    ) {}

    public function key(): string
    {
        return json_encode([$this->provider, $this->kind, $this->paymentId, $this->occurredFrom, $this->occurredTo], JSON_THROW_ON_ERROR);
    }

    /** @param  array<string, mixed>  $input */
    public static function fromInput(array $input): self
    {
        $rules = [
            'provider' => ['sometimes', Rule::in(self::PROVIDERS)],
            'kind' => ['sometimes', Rule::in(self::KINDS)],
            'payment_id' => ['sometimes', 'uuid'],
            'occurred_from' => ['sometimes', 'date_format:Y-m-d'],
            'occurred_to' => ['sometimes', 'date_format:Y-m-d', ...(isset($input['occurred_from']) ? ['after_or_equal:occurred_from'] : [])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'cursor' => ['sometimes', 'string', 'max:2048'],
            ...ChangeWindow::rules(),
        ];
        $unknown = array_diff(array_keys($input), array_keys($rules));
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, 'This filter is not supported by the processor movement change feed.'));
        }
        $validated = Validator::make($input, $rules)->validate();

        return new self(
            $validated['provider'] ?? null,
            $validated['kind'] ?? null,
            $validated['payment_id'] ?? null,
            $validated['occurred_from'] ?? null,
            $validated['occurred_to'] ?? null,
            (int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE),
            ChangeWindow::from($validated),
            isset($validated['cursor']) && $validated['cursor'] !== '' ? $validated['cursor'] : null,
        );
    }
}
