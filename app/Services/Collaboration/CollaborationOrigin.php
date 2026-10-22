<?php

namespace App\Services\Collaboration;

/** Chosen by a trusted adapter, never submitted by a client. */
enum CollaborationOrigin
{
    case Native;
    case Agent;
}
