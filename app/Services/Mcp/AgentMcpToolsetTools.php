<?php

namespace App\Services\Mcp;

use App\Services\Mcp\Toolsets\McpToolsets;
use App\Services\Mcp\Toolsets\McpToolsetSelection;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Notification\ToolListChangedNotification;
use Mcp\Server\RequestContext;

/**
 * The core tools that list, enable and disable toolsets.
 *
 * They change which tools this one session lists and nothing else: a toolset
 * is built only from tools the caller is already authorized for, so enabling
 * one reveals them and grants no access, and every call to a revealed tool is
 * authorized again as always. A name the caller could not use is refused the
 * same way a name that does not exist is.
 */
final class AgentMcpToolsetTools
{
    public function __construct(private readonly McpToolsets $toolsets) {}

    /** @return array<string, mixed> */
    public function list(RequestContext $context): array
    {
        return $this->result($this->selection($context), false);
    }

    /**
     * @param  list<string>  $toolsets
     * @return array<string, mixed>
     */
    public function enable(array $toolsets, RequestContext $context): array
    {
        $current = $this->selection($context);
        $names = $this->names($toolsets);
        $unknown = array_values(array_filter($names, fn (string $name): bool => ! $this->toolsets->has($name)));
        if ($unknown !== []) {
            throw $this->unknown($unknown);
        }

        return $this->change($context, $current, $current->enable($names));
    }

    /**
     * @param  list<string>  $toolsets
     * @return array<string, mixed>
     */
    public function disable(array $toolsets, RequestContext $context): array
    {
        $current = $this->selection($context);
        $names = $this->names($toolsets);
        // A toolset enabled earlier can be switched off even after the caller
        // lost access to it; only a name that is neither is refused.
        $unknown = array_values(array_filter($names, fn (string $name): bool => ! $this->toolsets->has($name) && ! $current->includes($name)));
        if ($unknown !== []) {
            throw $this->unknown($unknown);
        }

        return $this->change($context, $current, $current->disable($names));
    }

    private function selection(RequestContext $context): McpToolsetSelection
    {
        return McpToolsetSelection::fromSession($context->getSession()->get(McpToolsetSelection::SESSION_KEY));
    }

    /**
     * Store the new selection, then tell the client the tool list changed.
     * The selection is stored first: the notification suspends this handler
     * while the transport streams it, and the session is saved before that.
     *
     * @return array<string, mixed>
     */
    private function change(RequestContext $context, McpToolsetSelection $before, McpToolsetSelection $after): array
    {
        $changed = $before->toSession() !== $after->toSession();
        if ($changed) {
            $context->getSession()->set(McpToolsetSelection::SESSION_KEY, $after->toSession());
            $context->getClientGateway()->notify(new ToolListChangedNotification);
        }

        return $this->result($after, $changed);
    }

    /** @return array<string, mixed> */
    private function result(McpToolsetSelection $selection, bool $changed): array
    {
        return ['data' => [
            'mode' => $selection->all ? 'all' : 'progressive',
            'changed' => $changed,
            'toolsets' => $this->toolsets->report($selection),
        ]];
    }

    /**
     * @param  list<string>  $requested
     * @return list<string>
     */
    private function names(array $requested): array
    {
        return array_values(array_unique(array_map(static fn (mixed $name): string => strtolower(trim((string) $name)), $requested)));
    }

    /** @param  list<string>  $names */
    private function unknown(array $names): ToolCallException
    {
        return new ToolCallException('Unknown toolset: '.implode(', ', $names).'. Call toolsets.list for the toolsets available to this connection.');
    }
}
