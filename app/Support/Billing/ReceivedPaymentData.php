<?php

namespace App\Support\Billing;

use App\Models\ClientInvoicePayment;
use App\Services\Billing\MoneyService;
use DomainException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Validated money already received; no processor interaction or persistence. */
final readonly class ReceivedPaymentData
{
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

    /** @param array<string, mixed> $input */
    public static function from(array $input, bool $requireDate = true, bool $includeBookkeeping = false, ?string $errorPrefix = null): self
    {
        $rules = self::rules($requireDate, $includeBookkeeping);
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
    public static function rules(bool $requireDate = true, bool $includeBookkeeping = false): array
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
                'notes' => ['nullable', 'string', 'max:10000'],
                'status' => ['nullable', Rule::in(InvoicePaymentStatus::all())],
                'external_finance_transaction_uuid' => ['nullable', 'uuid'],
                'idempotency_key' => ['nullable', 'string', 'max:255'],
            ];
        }

        return $rules;
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
