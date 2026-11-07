<?php

namespace App\Exceptions;

use App\Support\Billing\RetryableConflict;
use DomainException;

/** The client changed since its version was read: re-read and retry (#636). */
final class AutopayVersionConflict extends DomainException implements RetryableConflict {}
