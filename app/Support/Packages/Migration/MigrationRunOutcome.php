<?php

namespace App\Support\Packages\Migration;

/** What one scheduled run did with one subscriber due to move (MIG-3). */
enum MigrationRunOutcome: string
{
    case NotDue = 'not_due';
    case Applied = 'applied';
    case Skipped = 'skipped';
    case Held = 'held';
}
