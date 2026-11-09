<?php

namespace App\Support\Packages\Migration;

/**
 * Why scheduling or cancelling a move was refused, or why one subscriber
 * cannot be part of one (MIG-1, MIG-5). Codes are part of the agent API and
 * never change.
 */
enum MigrationRefusal: string
{
    case NotNewer = 'package_migration_not_newer';
    case NoSubscribers = 'package_migration_no_subscribers';
    case NotASubscriber = 'package_migration_not_a_subscriber';
    case AlreadyScheduled = 'package_migration_already_scheduled';
    case EndsFirst = 'package_migration_ends_first';
    case DateNotOffered = 'package_migration_date_not_offered';
    case CurrencyChanged = 'package_migration_currency_changed';
    case NotScheduled = 'package_migration_not_scheduled';
    case EffectiveDateReached = 'package_migration_effective_date_reached';

    public function message(): string
    {
        return match ($this) {
            self::NotNewer => 'Subscribers can only move to the package\'s published version, from an older version of the same package.',
            self::NoSubscribers => 'Choose at least one subscriber to move.',
            self::NotASubscriber => 'A chosen subscriber is not an active service on the version being moved from.',
            self::AlreadyScheduled => 'A chosen subscriber is already part of another scheduled move. Cancel that move first.',
            self::EndsFirst => 'This agreement ends before the earliest date its notice period allows, so it keeps its terms.',
            self::DateNotOffered => 'That effective date is not a billing period start after every chosen subscriber\'s notice period. Choose one of the offered dates, or leave out the subscribers it does not suit.',
            self::CurrencyChanged => 'The new version bills in another currency. A move keeps a subscriber\'s currency; offer the new version as a new service instead.',
            self::NotScheduled => 'This move is no longer scheduled: it has completed or was cancelled.',
            self::EffectiveDateReached => 'This move has reached its effective date, so it can no longer be cancelled.',
        };
    }
}
