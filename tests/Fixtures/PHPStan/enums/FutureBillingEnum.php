<?php

declare(strict_types=1);

namespace Tests\Fixtures\PHPStan\enums;

/** A fixture proving new billing enums need no rule registration edit. */
enum FutureBillingEnum
{
    case Automatic;
    case Manual;
}

function futureBillingMatch(FutureBillingEnum $decision): string
{
    return match ($decision) {
        FutureBillingEnum::Automatic => 'automatic',
        default => 'manual or something added later',
    };
}
