<?php

namespace App\Support\Packages;

/** Whether an accepted (and, if required, paid) sign-up starts on its own or waits for an owner or admin. */
enum PackageActivationMode: string
{
    case Automatic = 'automatic';
    case Review = 'review';
}
