<?php

namespace App\Support\Packages;

/**
 * Where a company's autopay stands on the client's own consent: the limit, in
 * minor units, and the currency the client authorized it in (ACC-4).
 *
 * Pure, so whether it meets a version's terms is decided without a database.
 */
final readonly class ClientAutopay
{
    public function __construct(
        public int $limit,
        public string $currency,
    ) {}

    /**
     * Whether it meets a version that requires autopay: exactly the version's
     * limit, in the version's currency. A limit in another currency is another
     * limit, however equal the number.
     */
    public static function meets(?self $autopay, PackageTerms $terms): bool
    {
        return $terms->requireAutopay
            && $autopay instanceof self
            && $autopay->limit === $terms->autopayLimitAmount
            && $autopay->currency === strtoupper($terms->currency);
    }
}
