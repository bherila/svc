<?php

namespace App\Support\Packages;

/** What a client can do about ending an agreement from the portal (CAN-1, CAN-3). */
enum CancellationMode: string
{
    case None = 'none';
    case Request = 'request';
    case SelfServe = 'self_serve';
}
