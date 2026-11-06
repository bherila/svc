<?php

namespace App\Support\Time;

/**
 * How a person reads a length of time.
 *
 * Stored as whole minutes everywhere; this only decides the words. A per-user
 * preference (`users.duration_style`), except on invoices, which are always
 * decimal so a document means the same thing to every reader.
 */
enum DurationStyle: string
{
    case Decimal = 'decimal';
    case Clock = 'clock';
}
