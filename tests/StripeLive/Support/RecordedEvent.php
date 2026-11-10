<?php

namespace Tests\StripeLive\Support;

use InvalidArgumentException;
use JsonException;

/** One Stripe event as the Events API returned it: the exact JSON, plus what the matcher needs. */
final readonly class RecordedEvent
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public string $id,
        public string $type,
        public int $created,
        public array $data,
        public string $json,
    ) {}

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new InvalidArgumentException('A Stripe event was not valid JSON.', 0, $failure);
        }
        if (! is_array($data) || ! is_string($data['id'] ?? null) || ! is_string($data['type'] ?? null)) {
            throw new InvalidArgumentException('A Stripe event needs an id and a type.');
        }

        return new self($data['id'], $data['type'], is_int($data['created'] ?? null) ? $data['created'] : 0, $data, $json);
    }

    /**
     * The object the event reports (`data.object`), empty when it has none.
     *
     * @return array<string, mixed>
     */
    public function object(): array
    {
        $object = $this->data['data']['object'] ?? null;

        return is_array($object) ? $object : [];
    }

    /**
     * Every Stripe id the event's object points at: itself, and the intent,
     * customer, setup intent and payment method it belongs to.
     *
     * @return list<string>
     */
    public function references(): array
    {
        $object = $this->data['data']['object'] ?? null;
        if (! is_array($object)) {
            return [];
        }
        $ids = [];
        foreach (['id', 'payment_intent', 'customer', 'setup_intent', 'payment_method'] as $field) {
            $value = $object[$field] ?? null;
            if (is_string($value) && $value !== '') {
                $ids[] = $value;
            }
        }

        return array_values(array_unique($ids));
    }
}
