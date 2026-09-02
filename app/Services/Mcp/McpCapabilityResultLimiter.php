<?php

namespace App\Services\Mcp;

use Mcp\Schema\JsonRpc\Response;
use Throwable;

/** Enforces the reviewed serialized MCP capability-result ceiling. */
final class McpCapabilityResultLimiter
{
    /** @param Response<mixed> $response */
    public function exceeds(Response $response): bool
    {
        try {
            $encoded = json_encode($response->result, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return true;
        }

        $limit = config('agent_api.mcp_max_result_bytes');

        return ! is_int($limit) || $limit < 1 || strlen($encoded) > $limit;
    }
}
