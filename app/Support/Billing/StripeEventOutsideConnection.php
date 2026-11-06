<?php

namespace App\Support\Billing;

use RuntimeException;

/**
 * A verified Stripe event whose records belong to a workspace that is not
 * connected to the account it came from. Thrown inside the handler's
 * transaction so nothing is written; the event is acknowledged and ignored.
 */
final class StripeEventOutsideConnection extends RuntimeException {}
