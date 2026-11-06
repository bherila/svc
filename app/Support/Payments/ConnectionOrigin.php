<?php

namespace App\Support\Payments;

/** How a workspace payment connection came to exist. */
enum ConnectionOrigin: string
{
    /** Keys typed in by an owner or admin of the workspace. */
    case Entered = 'entered';

    /** Borrowed from another workspace with the same owner. */
    case Reused = 'reused';

    /** Made once, by hand, from the deployment's environment credentials. */
    case Environment = 'environment';
}
