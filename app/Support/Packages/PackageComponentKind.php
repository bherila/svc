<?php

namespace App\Support\Packages;

/**
 * A component either recurs on the agreement, or is a one-time setup fee.
 *
 * A setup fee is charged on the sign-up invoice when the version says so, and
 * otherwise billed once with the first cycle as a one-time recurring item -
 * never both (ACC-3).
 */
enum PackageComponentKind: string
{
    case Recurring = 'recurring';
    case SetupFee = 'setup_fee';
}
