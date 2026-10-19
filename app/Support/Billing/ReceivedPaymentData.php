<?php

namespace App\Support\Billing;

use App\Models\ClientInvoicePayment;
use App\Services\Billing\MoneyService;
use DomainException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Validated money already received; no processor interaction or persistence.
 *
 * The one rule set for recording a payment by hand: the browser form, the
 * `payments.record` operation (REST and MCP) and a payment folded into
 * `invoices.issue` all parse through here, so a value one door refuses the
 * others refuse too (#454).
 */
final readonly class ReceivedPaymentData
{
    /**
     * What a payment may be recorded as. A cheque that has not cleared is
     * `pending` and money that has is `succeeded`; every other status is where
     * a recorded payment ends up, and is reached by transitioning it
     * (`payments.set_status`, `payments.refund`) so the history says what
     * happened rather than a row appearing already failed or refunded.
     */
    public const array RECORDABLE_STATUSES = [InvoicePaymentStatus::Pending, InvoicePaymentStatus::Succeeded];

    public const int NOTES_MAX_LENGTH = PaymentCorrection::NOTES_MAX_LENGTH;

    private function __construct(
        public int $amount,
        public string $currency,
        public ?string $receivedOn,
        public string $method,
        public ?string $reference,
        public InvoicePaymentStatus $status,
        public ?string $notes,
        public ?string $externalFinanceTransactionUuid,
        public ?string $idempotencyKey,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  bool  $includeBookkeeping  accept the optional `notes`, `status` and `external_finance_transaction_uuid`
     * @param  bool  $acceptsBodyKey  accept an `idempotency_key` field; API callers send theirs as a header instead
     */
    public static function from(array $input, bool $requireDate = true, bool $includeBookkeeping = false, ?string $errorPrefix = null, bool $acceptsBodyKey = false): self
    {
        $rules = self::rules($requireDate, $includeBookkeeping, $acceptsBodyKey);
        if ($errorPrefix === null) {
            $data = Validator::make($input, $rules)->validate();
        } else {
            $nested = [];
            foreach ($rules as $field => $rule) {
                $nested[$errorPrefix.'.'.$field] = $rule;
            }
            $validated = Validator::make([$errorPrefix => $input], $nested)->validate();
            $data = $validated[$errorPrefix] ?? null;
            if (! is_array($data)) {
                throw new DomainException('Received-payment facts must be an object.');
            }
        }

        return new self(MoneyService::nonNegativeInteger($data['amount'], 'amount'), self::text($data['currency']), self::optionalText($data['received_on'] ?? null), self::text($data['method']), self::optionalText($data['reference'] ?? null),
            InvoicePaymentStatus::from(self::text($data['status'] ?? InvoicePaymentStatus::Succeeded->value)), self::optionalText($data['notes'] ?? null),
            self::optionalText($data['external_finance_transaction_uuid'] ?? null), self::optionalText($data['idempotency_key'] ?? null));
    }

    /** @return array<string, list<mixed>> */
    public static function rules(bool $requireDate = true, bool $includeBookkeeping = false, bool $acceptsBodyKey = false): array
    {
        $rules = [
            'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'received_on' => [$requireDate ? 'required' : 'nullable', 'date_format:Y-m-d'],
            'method' => ['required', 'string', 'max:'.ClientInvoicePayment::METHOD_MAX_LENGTH],
            'reference' => ['nullable', 'string', 'max:255'],
        ];
        if ($includeBookkeeping) {
            $rules += [
                'notes' => ['nullable', 'string', 'max:'.self::NOTES_MAX_LENGTH],
                'status' => ['nullable', Rule::in(self::recordableStatusValues())],
                'external_finance_transaction_uuid' => ['nullable', 'uuid'],
            ];
        }
        if ($acceptsBodyKey) {
            $rules['idempotency_key'] = ['nullable', 'string', 'max:255'];
        }

        return $rules;
    }

    /** @return list<string> */
    public static function recordableStatusValues(): array
    {
        return array_map(static fn (InvoicePaymentStatus $status): string => $status->value, self::RECORDABLE_STATUSES);
    }

    /** @return array<string, mixed> */
    public function attributes(?string $idempotencyKey = null): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency, 'received_on' => $this->receivedOn, 'method' => $this->method,
            'reference' => $this->reference, 'status' => $this->status->value, 'notes' => $this->notes,
            'external_finance_transaction_uuid' => $this->externalFinanceTransactionUuid, 'idempotency_key' => $idempotencyKey ?? $this->idempotencyKey];
    }

    private static function text(mixed $value): string
    {
        if (! is_string($value)) {
            throw new DomainException('A received-payment text field must be a string.');
        }

        return $value;
    }

    private static function optionalText(mixed $value): ?string
    {
        return $value === null ? null : self::text($value);
    }
}
