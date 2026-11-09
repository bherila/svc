<?php

namespace App\Support\Packages;

/** How a package agreement's recurring invoices are produced (INV-1, INV-2). */
enum PackageInvoiceIssuing: string
{
    case Automatic = 'automatic';
    case Draft = 'draft';
}
