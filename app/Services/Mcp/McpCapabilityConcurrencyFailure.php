<?php

namespace App\Services\Mcp;

enum McpCapabilityConcurrencyFailure
{
    case Limited;
    case Unavailable;
}
