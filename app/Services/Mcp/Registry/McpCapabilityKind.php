<?php

namespace App\Services\Mcp\Registry;

enum McpCapabilityKind: string
{
    case Tool = 'tool';
    case Resource = 'resource';
    case ResourceTemplate = 'resource_template';
    case Prompt = 'prompt';
}
