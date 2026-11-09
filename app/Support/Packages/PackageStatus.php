<?php

namespace App\Support\Packages;

/** Where a service package stands: offered to nobody yet, offered, or closed to new sign-ups (PKG-6). */
enum PackageStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Retired = 'retired';
}
