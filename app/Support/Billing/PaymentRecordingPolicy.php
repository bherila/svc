<?php

namespace App\Support\Billing;

/** Selected by a trusted adapter, never by a payment request's body. */
enum PaymentRecordingPolicy
{
    case Agent;
    case OperatorBookkeeping;
}
