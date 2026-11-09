<?php

namespace App\Support\Packages;

/**
 * A client's subscription to a package, from offer to service.
 *
 * Approval creates an enrollment `offered`. Acceptance, payment and
 * activation (slice 3) move it through the rest.
 */
enum EnrollmentStatus: string
{
    case Offered = 'offered';
    case AwaitingPayment = 'awaiting_payment';
    case AwaitingReview = 'awaiting_review';
    case Active = 'active';
    case Lapsed = 'lapsed';
    case Withdrawn = 'withdrawn';
}
