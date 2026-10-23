<?php

namespace App\Services\Collaboration;

/** Selected by the trusted transport, never from submitted input. */
enum CollaborationAudience: string
{
    case Internal = 'internal';
    case Portal = 'portal';
}
