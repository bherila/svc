<?php

namespace App\Support\Finance;

use Carbon\CarbonImmutable;
use DomainException;

/**
 * One authoritative processor balance entry, before tenant-owned persistence.
 * The fee is signed expense (positive when charged), so net = principal - fee.
 * It describes this entry only, not completeness of all fees for the payment.
 *
 * Payment identifiers are provider identities for an explicit ownership lookup;
 * they are not a substitute for resolving the payment in its own workspace.
 */
final readonly class ProcessorMovementFacts
{
    public const int MAX_MINOR = 9007199254740991;

    public string $provider;

    public string $identifier;

    /** @var list<string> */
    public array $paymentIdentifiers;

    public ?string $sourceIdentifier;

    public ProcessorMovementKind $kind;

    public int $principalMinor;

    public int $feeMinor;

    public int $netMinor;

    public string $currency;

    public CarbonImmutable $occurredAt;

    public string $dateBasis;

    public ?CarbonImmutable $availableOn;

    public ?string $payoutIdentifier;

    /** Raw scalar inputs are validated before assignment, without numeric coercion. */
    public function __construct(
        mixed $provider = null,
        #[\SensitiveParameter] mixed $identifier = null,
        #[\SensitiveParameter] mixed $paymentIdentifiers = null,
        #[\SensitiveParameter] mixed $sourceIdentifier = null,
        mixed $kind = null,
        #[\SensitiveParameter] mixed $principalMinor = null,
        #[\SensitiveParameter] mixed $feeMinor = null,
        #[\SensitiveParameter] mixed $netMinor = null,
        mixed $currency = null,
        mixed $occurredAt = null,
        mixed $dateBasis = null,
        mixed $availableOn = null,
        #[\SensitiveParameter] mixed $payoutIdentifier = null,
    ) {
        if (! in_array($provider, ['stripe', 'square'], true)
            || ! self::identifier($identifier)
            || ($sourceIdentifier !== null && ! self::identifier($sourceIdentifier))
            || ($payoutIdentifier !== null && ! self::identifier($payoutIdentifier))
            || ! is_array($paymentIdentifiers) || ! array_is_list($paymentIdentifiers)
            || count($paymentIdentifiers) < 1 || count($paymentIdentifiers) > 4
            || ! $kind instanceof ProcessorMovementKind
            || ! self::minor($principalMinor) || ! self::minor($feeMinor) || ! self::minor($netMinor)
            || ! is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1
            || ! $occurredAt instanceof CarbonImmutable
            || ($availableOn !== null && ! $availableOn instanceof CarbonImmutable)
            || ! is_string($dateBasis) || $dateBasis !== ($provider === 'stripe' ? 'stripe_balance_created' : 'square_effective_at')) {
            throw self::unreadable();
        }
        foreach ($paymentIdentifiers as $paymentIdentifier) {
            if (! self::identifier($paymentIdentifier)) {
                throw self::unreadable();
            }
        }
        if (count(array_unique($paymentIdentifiers, SORT_STRING)) !== count($paymentIdentifiers)) {
            throw self::unreadable();
        }
        // Each operand is bounded at 2^53-1, so subtraction remains an exact
        // integer on the supported 64-bit runtime even when its result is refused.
        $calculatedNet = $principalMinor - $feeMinor;
        $signMatches = match ($kind) {
            ProcessorMovementKind::Refund, ProcessorMovementKind::DisputeDebit => $principalMinor < 0,
            ProcessorMovementKind::RefundReversal, ProcessorMovementKind::DisputeCredit => $principalMinor > 0,
            ProcessorMovementKind::Fee => $principalMinor === 0 && $feeMinor > 0,
            ProcessorMovementKind::FeeReversal => $principalMinor === 0 && $feeMinor < 0,
        };
        if (! $signMatches || ! self::minor($calculatedNet) || $netMinor !== $calculatedNet) {
            throw self::unreadable();
        }

        $this->provider = $provider;
        $this->identifier = $identifier;
        /** @var list<string> $paymentIdentifiers */
        $this->paymentIdentifiers = $paymentIdentifiers;
        $this->sourceIdentifier = $sourceIdentifier;
        $this->kind = $kind;
        $this->principalMinor = $principalMinor;
        $this->feeMinor = $feeMinor;
        $this->netMinor = $netMinor;
        $this->currency = $currency;
        $this->occurredAt = $occurredAt->utc();
        $this->dateBasis = $dateBasis;
        $this->availableOn = $availableOn?->utc();
        $this->payoutIdentifier = $payoutIdentifier;
    }

    /** @phpstan-assert-if-true string $value */
    private static function identifier(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '' && strlen($value) <= 255;
    }

    /** @phpstan-assert-if-true int $value */
    private static function minor(mixed $value): bool
    {
        return is_int($value) && $value >= -self::MAX_MINOR && $value <= self::MAX_MINOR;
    }

    private static function unreadable(): DomainException
    {
        return new DomainException('The processor movement facts are unreadable.');
    }
}
