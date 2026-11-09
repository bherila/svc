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
    case NotRecurring = 'package_migration_not_recurring';
    case NoSubscribers = 'package_migration_no_subscribers';
    case NotASubscriber = 'package_migration_not_a_subscriber';
    case AlreadyScheduled = 'package_migration_already_scheduled';
    case EndsFirst = 'package_migration_ends_first';
    case DateNotOffered = 'package_migration_date_not_offered';
    case CurrencyChanged = 'package_migration_currency_changed';
    case NotScheduled = 'package_migration_not_scheduled';
    case BillsByUsage = 'package_migration_bills_by_usage';

    public function message(): string
    {
        return match ($this) {
            self::NotNewer => 'Subscribers can only move to the package\'s published version, from an older version of the same package.',
            self::NotRecurring => 'This package does not bill in periods, so there is no period start for a move to take effect at.',
            self::NoSubscribers => 'Choose at least one subscriber to move.',
            self::NotASubscriber => 'A chosen subscriber is not an active service on the version being moved from.',
            self::AlreadyScheduled => 'A chosen subscriber is already part of another scheduled move. Cancel that move first.',
            self::EndsFirst => 'This agreement ends before the earliest date its notice period allows, so it keeps its terms.',
            self::DateNotOffered => 'That effective date is not a billing period start after every chosen subscriber\'s notice period. Choose one of the offered dates, or leave out the subscribers it does not suit.',
            self::CurrencyChanged => 'The new version bills in another currency. A move keeps a subscriber\'s currency; offer the new version as a new service instead.',
            self::NotScheduled => 'This move is no longer scheduled: it has completed or was cancelled.',
            self::BillsByUsage => 'This agreement bills by usage (an hourly rate or a retainer). Its last period\'s work is invoiced after the period ends, on the invoice that also opens the next period, so the billing engine cannot yet settle it on the old terms beside a new agreement on the same project. End it and offer the new version instead.',
        };
    }
}
