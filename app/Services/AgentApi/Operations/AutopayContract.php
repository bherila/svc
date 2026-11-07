<?php

namespace App\Services\AgentApi\Operations;

use App\Support\Billing\Autopay\AutopayAttemptState;
use App\Support\Billing\Autopay\AutopayInvoiceState;
use App\Support\Billing\Autopay\AutopayVerdict;
use InvalidArgumentException;

/**
 * The code-owned OpenAPI components of the autopay operations (#636),
 * regenerated into the public contract by scripts/generate-autopay-contract.php.
 * The operations themselves are declared beside the payment reminders in
 * {@see AgentOperationCatalog} and {@see AgentRestDocumentation}.
 */
final class AutopayContract
{
    /** @return list<string> */
    public static function components(): array
    {
        return [
            'CompanyAutopay', 'CompanyAutopayResponse', 'CompanyAutopayRequest',
            'InvoiceAutopay', 'InvoiceAutopayResponse', 'InvoiceAutopaySkipRequest', 'InvoiceAutopayResolveRequest',
        ];
    }

    /** @return array<string, mixed> */
    public static function schema(string $component): array
    {
        $uuid = ['$ref' => '#/components/schemas/Uuid'];
        $version = ['$ref' => '#/components/schemas/Version'];
        $text = ['type' => 'string'];
        $nullableText = ['type' => ['string', 'null']];
        $int = ['type' => 'integer'];
        $nullableInt = ['type' => ['integer', 'null']];
        $instant = ['type' => ['string', 'null'], 'format' => 'date-time'];
        $date = ['type' => ['string', 'null'], 'format' => 'date'];

        return [
            'CompanyAutopay' => self::object([
                'client_id' => $uuid,
                'version' => $version,
                'enabled' => ['type' => 'boolean'],
                'enabled_at' => $instant,
                'limit_amount' => $nullableInt,
                'currency' => $text,
                'method' => self::nullableObject([
                    'label' => $text, 'brand' => $nullableText, 'last4' => $nullableText,
                    'exp_month' => $nullableInt, 'exp_year' => $nullableInt,
                ]),
                'consents' => ['type' => 'array', 'maxItems' => 50, 'items' => self::object([
                    'id' => $uuid,
                    'action' => ['type' => 'string', 'enum' => ['enabled', 'disabled', 'limit_changed']],
                    'actor_name' => $nullableText,
                    'actor_role' => ['type' => 'string', 'enum' => ['client', 'provider', 'system']],
                    'method' => ['type' => 'string', 'enum' => ['portal_checkbox', 'provider_attestation', 'withdrawal', 'automatic']],
                    'limit_amount' => $nullableInt,
                    'currency' => $nullableText,
                    'payment_method_label' => $nullableText,
                    'wording' => $nullableText,
                    'attestation' => $nullableText,
                    'reason' => $nullableText,
                    'notice_sent' => ['type' => 'boolean'],
                    'occurred_at' => $instant,
                ])],
                'upcoming' => ['type' => 'array', 'maxItems' => 10, 'items' => self::object([
                    'invoice_id' => $uuid, 'invoice_number' => $text, 'due_on' => $date, 'amount' => $int, 'currency' => $text,
                    'state' => self::states(), 'state_label' => $text,
                ])],
                'connection_problem' => self::connectionProblem(),
            ]),
            'CompanyAutopayResponse' => self::object(['data' => ['$ref' => '#/components/schemas/CompanyAutopay']]),
            'CompanyAutopayRequest' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['expected_version', 'enabled', 'confirm'],
                'properties' => [
                    'expected_version' => $version,
                    'enabled' => ['type' => 'boolean'],
                    'limit_amount' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The most one invoice may be charged, in minor units. Required to turn autopay on or change its limit.'],
                    'attestation' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 1000, 'description' => 'How the client agreed to autopay outside SVC. Required to turn autopay on or change its limit.'],
                    'confirm' => ['type' => 'boolean', 'const' => true, 'description' => 'Must be true, only after the user explicitly confirms. Turning autopay on authorizes charges to the client\'s card; turning it off is confirmed the same way.'],
                ],
            ],
            'InvoiceAutopay' => self::object([
                'invoice_id' => $uuid,
                'invoice_version' => $version,
                'state' => self::states(),
                'state_label' => $text,
                'enabled' => ['type' => 'boolean'],
                'limit_amount' => $nullableInt,
                'currency' => $text,
                'amount' => $int,
                'method_label' => $nullableText,
                'will_charge_on' => $date,
                'verdict' => ['type' => 'string', 'enum' => array_map(static fn (AutopayVerdict $verdict): string => $verdict->value, AutopayVerdict::cases())],
                'can_skip' => ['type' => 'boolean'],
                'connection_problem' => self::connectionProblem(),
                'can_resolve' => ['type' => 'boolean'],
                'attempt' => self::nullableObject([
                    'id' => $uuid,
                    'state' => ['type' => 'string', 'enum' => array_map(static fn (AutopayAttemptState $state): string => $state->value, AutopayAttemptState::cases())],
                    'amount' => $nullableInt,
                    'currency' => $nullableText,
                    'payment_method_label' => $nullableText,
                    'failure_code' => $nullableText,
                    'failure_reason' => $nullableText,
                    'payment_status' => $nullableText,
                    'skipped_by_name' => $nullableText,
                    'skipped_at' => $instant,
                    'resolved_by_name' => $nullableText,
                    'resolution_note' => $nullableText,
                    'claimed_at' => $instant,
                    'resolved_at' => $instant,
                ]),
            ]),
            'InvoiceAutopayResponse' => self::object(['data' => ['$ref' => '#/components/schemas/InvoiceAutopay']]),
            'InvoiceAutopaySkipRequest' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['expected_version', 'confirm'],
                'properties' => ['expected_version' => $version, 'confirm' => ['type' => 'boolean', 'const' => true]],
            ],
            'InvoiceAutopayResolveRequest' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['expected_version', 'outcome', 'confirm'],
                'properties' => [
                    'expected_version' => $version,
                    'outcome' => ['type' => 'string', 'enum' => ['charged', 'not_charged'], 'description' => 'charged: Stripe shows the client was charged; needs payment_intent_id. not_charged: nothing was charged; needs note.'],
                    'payment_intent_id' => ['type' => 'string', 'pattern' => '^pi_[A-Za-z0-9_]{1,252}$', 'description' => 'The Stripe PaymentIntent id. Required when outcome is charged; refused when it is not this invoice\'s attempt.'],
                    'note' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 255, 'description' => 'What was checked in Stripe. Required when outcome is not_charged.'],
                    'confirm' => ['type' => 'boolean', 'const' => true, 'description' => 'Must be true, only after the user explicitly confirms the outcome they found in Stripe.'],
                ],
            ],
        ][$component] ?? throw new InvalidArgumentException("Unknown autopay component {$component}.");
    }

    /** @return array<string, mixed> */
    private static function connectionProblem(): array
    {
        return self::nullableObject([
            'since' => ['type' => 'string', 'format' => 'date-time'],
            'message' => ['type' => 'string'],
        ]);
    }

    /** @return array<string, mixed> */
    private static function states(): array
    {
        return ['type' => 'string', 'enum' => array_map(static fn (AutopayInvoiceState $state): string => $state->value, AutopayInvoiceState::cases())];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private static function nullableObject(array $fields): array
    {
        return ['type' => ['object', 'null']] + self::object($fields);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private static function object(array $fields): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($fields), 'properties' => $fields];
    }
}
