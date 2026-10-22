<?php

namespace App\Support\AgentApi;

use Illuminate\Support\Facades\Config;

/** Shared REST/MCP/CLI pages must also fit MCP's duplicated result encoding. */
final class AgentPageBudget
{
    public static function dataBytes(): int
    {
        // MCP includes structured data and a JSON text copy. Together they can
        // cost three times ordinary JSON (for example, escaped backslashes).
        // Reserve space for both copies of cursor/watermark/envelope metadata.
        $ceiling = min(Config::integer('agent_api.mcp_max_result_bytes'), Config::integer('agent_api.mcp_max_response_body_bytes'));

        return max(1, intdiv($ceiling - 16_384, 3));
    }
}
