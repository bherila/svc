<?php

namespace App\Support\Billing;

use RuntimeException;

/**
 * A Stripe event whose handling read something that moved before it could be
 * applied. Nothing was written; answering with an error makes Stripe deliver
 * it again, and the retry reads afresh. Not a domain failure, so the event is
 * not recorded as failed.
 */
final class StripeEventMustBeRetried extends RuntimeException {}
