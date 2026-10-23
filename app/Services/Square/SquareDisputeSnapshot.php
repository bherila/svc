<?php

namespace App\Services\Square;

use App\Support\Square\SquareDisputeFacts;

/** A complete bounded provider read, bound to local revisions captured before HTTP. */
final readonly class SquareDisputeSnapshot
{
    /**
     * @param  array<string, int>  $paymentVersions
     * @param  array<string, list<SquareDisputeFacts>>  $groups
     * @param  array<string, string>  $paymentByDispute
     */
    public function __construct(
        public array $paymentVersions,
        public array $groups,
        public array $paymentByDispute,
    ) {}
}
