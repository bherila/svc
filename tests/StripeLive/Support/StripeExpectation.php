<?php

namespace Tests\StripeLive\Support;

/**
 * One event a scenario must see before it replays anything. A type alone is
 * too weak when one customer produces several events of the same type: setup
 * tags the customer (a `customer.updated` with metadata and no default) before
 * the default method is set (a second `customer.updated`), and the first would
 * otherwise satisfy a wait for the second. So an expectation can also name the
 * object it is about and the default payment method the event must carry.
 */
final readonly class StripeExpectation
{
    private function __construct(
        public string $type,
        private ?string $objectId,
        private bool $checksDefault,
        private ?string $defaultPaymentMethod,
    ) {}

    /** Any event of this type. A plain string stands for the same. */
    public static function type(string $type): self
    {
        return new self($type, null, false, null);
    }

    /** An event of this type whose object is this one (a method, an intent, a customer). */
    public static function forObject(string $type, string $objectId): self
    {
        return new self($type, $objectId, false, null);
    }

    /**
     * A `customer.updated` for this customer whose `invoice_settings.default_payment_method`
     * is exactly this method; null means the event must report no default.
     */
    public static function customerDefault(string $customerId, ?string $paymentMethodId): self
    {
        return new self('customer.updated', $customerId, true, $paymentMethodId);
    }

    public static function of(self|string $expectation): self
    {
        return $expectation instanceof self ? $expectation : self::type($expectation);
    }

    public function isMetBy(RecordedEvent $event): bool
    {
        if ($event->type !== $this->type) {
            return false;
        }
        $object = $event->object();
        if ($this->objectId !== null && ($object['id'] ?? null) !== $this->objectId) {
            return false;
        }
        if (! $this->checksDefault) {
            return true;
        }
        $settings = $object['invoice_settings'] ?? null;
        if (! is_array($settings) || ! array_key_exists('default_payment_method', $settings)) {
            return false;
        }
        $default = $settings['default_payment_method'];
        $default = is_array($default) ? ($default['id'] ?? null) : $default;

        return $default === $this->defaultPaymentMethod && ($default === null || is_string($default));
    }

    public function describe(): string
    {
        if ($this->checksDefault) {
            return sprintf('%s for %s with default payment method %s', $this->type, $this->objectId, $this->defaultPaymentMethod ?? 'none');
        }

        return $this->objectId === null ? $this->type : sprintf('%s for %s', $this->type, $this->objectId);
    }
}
