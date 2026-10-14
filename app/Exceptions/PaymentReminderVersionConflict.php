<?php

namespace App\Exceptions;

use App\Support\Billing\RetryableConflict;
use DomainException;

/** The invoice or client changed since its version was read: re-read and retry (#438). */
final class PaymentReminderVersionConflict extends DomainException implements RetryableConflict {}
