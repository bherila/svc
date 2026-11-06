<?php

namespace App\Services\Payments;

use RuntimeException;

/** The processor refused a credential. The message is safe to show: it never contains the credential. */
final class PaymentCredentialsRejected extends RuntimeException
{
    /** @param  string  $field  The form field the refusal belongs to. */
    public function __construct(string $message, public readonly string $field = 'secret')
    {
        parent::__construct($message);
    }
}
