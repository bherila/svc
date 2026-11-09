<?php

namespace App\Support\Packages;

/** What an enrollment `awaiting_payment` is waiting for. Values are part of the agent API. */
enum SignupWait: string
{
    /** The sign-up invoice, for a version that starts only once paid. */
    case Payment = 'payment';

    /** A default saved card with autopay on at the version's limit, consented to by the client. */
    case Autopay = 'autopay';
}
