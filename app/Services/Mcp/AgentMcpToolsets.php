<?php

namespace App\Services\Mcp;

use Bherila\McpLaravelBridge\Capabilities\Operation;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Notification\ToolListChangedNotification;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/** Session visibility over the freshly authorized catalog; never an authority grant. */
final readonly class AgentMcpToolsets
{
    public const array CORE = ['context.get', 'search', 'toolsets.list', 'toolsets.enable', 'toolsets.disable', 'projects.list', 'tasks.list', 'time_entries.log'];

    /** @param list<Operation> $tools */
    public function __construct(private array $tools) {}

    /** @return array<string, list<string>> */
    public function groups(): array
    {
        $groups = [];
        foreach ($this->tools as $tool) {
            if (in_array($tool->mcpName(), self::CORE, true)) {
                continue;
            }
            // Square has a catalog discovery group while sharing billing scopes.
            if (in_array('mcp.switch:mcp.read.square', $tool->requirement->flags, true)) {
                $groups['square'][] = (string) $tool->mcpName();

                continue;
            }
            foreach ($tool->requirement->scopes as $scope) {
                $group = explode(':', $scope, 2)[0];
                if (! in_array($group, ['mcp'], true)) {
                    $groups[$group][] = (string) $tool->mcpName();
                }
            }
        }
        ksort($groups);

        return array_map(static fn (array $names): array => array_values(array_unique($names)), $groups);
    }

    public function visible(string $name, SessionInterface $session): bool
    {
        if (in_array($name, self::CORE, true)) {
            return true;
        }
        foreach ($this->groups() as $group => $names) {
            if ($this->enabled($group, $session) && in_array($name, $names, true)) {
                return true;
            }
        }

        return false;
    }

    private function enabled(string $group, SessionInterface $session): bool
    {
        $selection = $session->get('svc_toolsets', []);
        if (! $session->has('svc_toolsets') && $session->get('client_capabilities.experimental.svc/toolsets') === 'all') {
            return true;
        }

        return is_array($selection) && in_array($group, $selection, true);
    }

    /** @return array{data: list<array{name: string, description: string, tool_count: int, enabled: bool}>} */
    public function list(RequestContext $context): array
    {
        $data = [];
        foreach ($this->groups() as $name => $tools) {
            $data[] = ['name' => $name, 'description' => ucfirst(str_replace('_', ' ', $name)).' tools authorized for this connection.', 'tool_count' => count($tools), 'enabled' => $this->enabled($name, $context->getSession())];
        }

        return ['data' => $data];
    }

    /** @return array{data: list<array{name: string, description: string, tool_count: int, enabled: bool}>} */
    public function enable(string $name, RequestContext $context): array
    {
        return $this->change($name, true, $context);
    }

    /** @return array{data: list<array{name: string, description: string, tool_count: int, enabled: bool}>} */
    public function disable(string $name, RequestContext $context): array
    {
        return $this->change($name, false, $context);
    }

    /** @return array{data: list<array{name: string, description: string, tool_count: int, enabled: bool}>} */
    private function change(string $name, bool $enable, RequestContext $context): array
    {
        $groups = $this->groups();
        if (! isset($groups[$name])) {
            throw new ToolCallException('Toolset not found or not available.');
        }
        $session = $context->getSession();
        $enabled = array_values(array_filter(array_keys($groups), fn (string $group): bool => $group === $name ? $enable : $this->enabled($group, $session)));
        $session->set('svc_toolsets', $enabled);
        $session->save();
        $context->getClientGateway()->notify(new ToolListChangedNotification);

        return $this->list($context);
    }
}
