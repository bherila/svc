<?php

namespace App\Support\Payments;

/** A refusal belongs either to the current generation or to an obsolete request. */
enum StripeHealthRefusal
{
    case Obsolete;
    case First;
    case Repeated;
}
