<?php

namespace App\Support\Packages;

/** What a line of the sign-up charge is for. Values are part of the portal's props. */
enum SignupChargeKind: string
{
    case SetupFee = 'setup_fee';
    case FirstPeriod = 'first_period';
}
