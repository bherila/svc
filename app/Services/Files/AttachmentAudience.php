<?php

namespace App\Services\Files;

/** Chosen by a trusted adapter, never a request field. Native preserves operator-or-portal access. */
enum AttachmentAudience
{
    case Native;
    case Internal;
    case Portal;
    // Existing native owner/admin client preview; still enforces client-safe disclosure.
    case PortalPreview;
}
