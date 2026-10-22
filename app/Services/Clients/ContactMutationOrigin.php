<?php

namespace App\Services\Clients;

/** Chosen by a trusted adapter; request payloads cannot select their audience. */
enum ContactMutationOrigin
{
    case Internal;
    case Agent;
}
