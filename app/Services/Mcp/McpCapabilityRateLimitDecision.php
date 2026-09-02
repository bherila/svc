<?php

namespace App\Services\Mcp;

enum McpCapabilityRateLimitDecision
{
    case Allowed;
    case RateLimited;
    case Unavailable;
}
