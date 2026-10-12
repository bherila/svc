<?php

namespace App\Support\Square;

use InvalidArgumentException;

/**
 * A Square invoice as the person linking it stated it (#438).
 *
 * Validated for shape on construction: an id, a number, optionally the
 * currency Square bills it in, and how the match was found. A suggested match
 * carries the score and reasons the person saw when they confirmed it; a
 * manual one carries neither.
 */
final readonly class SquareInvoiceReference
{
    /** @param list<string>|null $reasons */
    public function __construct(
        public string $id,
        public string $number,
        public ?string $currency,
        public SquareMatchMethod $matchMethod,
        public ?int $score = null,
        public ?array $reasons = null,
    ) {
        if (! SquareIdentifiers::isInvoiceId($id)) {
            throw new InvalidArgumentException('The Square invoice id is not in a recognised format.');
        }
        if (! SquareIdentifiers::isInvoiceNumber($number)) {
            throw new InvalidArgumentException('The Square invoice number is not in a recognised format.');
        }
        if ($currency !== null && preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new InvalidArgumentException('The currency must be a three-letter code.');
        }
        if ($matchMethod === SquareMatchMethod::Manual && ($score !== null || $reasons !== null)) {
            throw new InvalidArgumentException('A manual link carries no score or reasons.');
        }
        if ($score !== null && ($score < 0 || $score > 100)) {
            throw new InvalidArgumentException('A match score is between 0 and 100.');
        }
    }

    public function seriesKey(): ?string
    {
        return SquareIdentifiers::seriesKey($this->number);
    }
}
