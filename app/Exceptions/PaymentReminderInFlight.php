<?php

namespace App\Exceptions;

use App\Support\Billing\RetryableConflict;

/** Another reminder for the invoice is leaving now: re-read and retry (#438). */
final class PaymentReminderInFlight extends PaymentReminderRefused implements RetryableConflict {}
