<?php

namespace App\Support\AgentApi;

/**
 * Whether this process is running an MCP tool's REST route right now (#459).
 *
 * In-process state, set only by {@see MarkedInternalAgentApiTransport} around
 * the nested request, so nothing a client sends can claim it. The REST group
 * reads it to leave a nested request out of the throttle the outer MCP
 * request has already been counted against.
 */
final class InternalApiDispatch
{
    private int $depth = 0;

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $dispatch
     * @return TResult
     */
    public function run(callable $dispatch): mixed
    {
        $this->depth++;
        try {
            return $dispatch();
        } finally {
            $this->depth--;
        }
    }

    public function active(): bool
    {
        return $this->depth > 0;
    }
}
