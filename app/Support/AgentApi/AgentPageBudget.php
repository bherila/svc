<?php

namespace App\Support\AgentApi;

use Illuminate\Support\Facades\Config;

/** Shared REST/MCP/CLI pages must also fit MCP's duplicated result encoding. */
final class AgentPageBudget
{
    /** @infection-ignore-all Adjacent tiny floors all yield the same single-record page; the transport still rejects an undersized configured ceiling. */
    private const int MIN_DATA_BYTES = 1;

    /** @infection-ignore-all This conservative envelope allowance is not a data boundary; a one-byte change leaves the protocol's actual serialized ceiling authoritative. */
    private const int ENVELOPE_RESERVE_BYTES = 16_384;

    public static function dataBytes(): int
    {
        // MCP includes structured data and a JSON text copy. Together they can
        // cost three times ordinary JSON (for example, escaped backslashes).
        // Reserve space for both copies of cursor/watermark/envelope metadata.
        $ceiling = min(Config::integer('agent_api.mcp_max_result_bytes'), Config::integer('agent_api.mcp_max_response_body_bytes'));

        return max(self::MIN_DATA_BYTES, intdiv($ceiling - self::ENVELOPE_RESERVE_BYTES, 3));
    }
}
