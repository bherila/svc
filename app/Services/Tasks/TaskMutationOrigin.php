<?php

namespace App\Services\Tasks;

/** Selected by a trusted server adapter; never accepted from request data. */
enum TaskMutationOrigin
{
    case Internal;
    case Agent;
}
