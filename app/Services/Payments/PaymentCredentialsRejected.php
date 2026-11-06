<?php

namespace App\Services\Payments;

use RuntimeException;

/** The processor refused a credential. The message is safe to show: it never contains the credential. */
final class PaymentCredentialsRejected extends RuntimeException {}
