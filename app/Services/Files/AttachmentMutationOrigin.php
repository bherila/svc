<?php

namespace App\Services\Files;

/** Server-owned transport identity; trusted native CLI uses Native. */
enum AttachmentMutationOrigin
{
    case Native;
    case Agent;
}
