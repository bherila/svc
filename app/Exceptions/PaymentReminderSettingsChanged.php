<?php

namespace App\Exceptions;

use App\Support\Billing\RetryableConflict;
use DomainException;

/** The client changed since its version was read: re-read and retry (#438). */
final class PaymentReminderSettingsChanged extends DomainException implements RetryableConflict {}
