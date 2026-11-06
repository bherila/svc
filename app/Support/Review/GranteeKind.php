<?php

declare(strict_types=1);

namespace App\Support\Review;

/** Who a reviewer grant names: one person's address, or every owner and admin of a workspace. */
enum GranteeKind: string
{
    case Contact = 'contact';
    case Workspace = 'workspace';
}
