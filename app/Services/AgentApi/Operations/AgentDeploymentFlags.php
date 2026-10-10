<?php

namespace App\Services\AgentApi\Operations;

use App\Services\Mcp\McpFeatureFlags;
use App\Support\AgentApi\FirstPartySession;
use Bherila\McpLaravelBridge\Capabilities\ConfigDeploymentFlags;
use Bherila\McpLaravelBridge\Capabilities\DeploymentFlags;

/**
 * The installation switches an operation can depend on.
 *
 * - **Write cutovers** (`AGENT_API_*_WRITES_ENABLED`) withhold a write surface
 *   from agents. Each nests inside the outer workflow cutover exactly as
 *   before (#242), except time-entry writes, which have their own emergency
 *   cutoff. For REST the signed-in website passes them - they exist to withhold
 *   agent access, and switching one off must not break the web UI on the same
 *   routes (#385). What agents are offered (MCP discovery, context.get) never
 *   takes that bypass, even when context.get is read on a browser session.
 * - **MCP switches** (`agent_api.mcp_enabled` and `mcp_feature_flags`, by
 *   group or tool name) belong to the MCP transport and never withhold REST.
 */
final class AgentDeploymentFlags implements DeploymentFlags
{
    public const string WRITES = 'agent.writes';

    public const string TIME_ENTRIES = 'agent.writes.time_entries';

    public const string INVOICES = 'agent.writes.invoices';

    public const string PAYMENTS = 'agent.writes.payments';

    public const string EXPENSES = 'agent.writes.expenses';

    public const string CLIENTS = 'agent.writes.clients';

    public const string PROJECTS = 'agent.writes.projects';

    public const string PROPOSALS = 'agent.writes.proposals';

    public const string WORKSPACES = 'agent.writes.workspaces';

    public const string FILES = 'agent.writes.files';

    /** The MCP transport as a whole. */
    public const string MCP = 'mcp';

    /** Prefix of a per-group or per-tool MCP switch: `mcp.switch:<key>`. */
    public const string MCP_SWITCH = 'mcp.switch:';

    private const array CUTOVERS = [
        self::WRITES => 'agent_api.writes_enabled',
        self::TIME_ENTRIES => ['key' => 'agent_api.time_entry_writes_enabled'],
        self::INVOICES => ['key' => 'agent_api.invoice_writes_enabled', 'parents' => [self::WRITES]],
        self::PAYMENTS => ['key' => 'agent_api.payment_writes_enabled', 'parents' => [self::WRITES]],
        self::EXPENSES => ['key' => 'agent_api.expense_writes_enabled', 'parents' => [self::WRITES]],
        self::CLIENTS => ['key' => 'agent_api.client_writes_enabled', 'parents' => [self::WRITES]],
        self::PROJECTS => ['key' => 'agent_api.project_writes_enabled', 'parents' => [self::WRITES]],
        self::PROPOSALS => ['key' => 'agent_api.proposal_writes_enabled', 'parents' => [self::WRITES]],
        self::WORKSPACES => ['key' => 'agent_api.workspace_writes_enabled', 'parents' => [self::WRITES]],
        self::FILES => ['key' => 'agent_api.file_writes_enabled', 'parents' => [self::WRITES]],
    ];

    private function __construct(
        private readonly ConfigDeploymentFlags $cutovers,
        private readonly ?McpFeatureFlags $mcp,
    ) {}

    /** REST: cutovers, which the signed-in website passes; MCP switches never apply. */
    public static function forRest(): self
    {
        return new self(new ConfigDeploymentFlags(self::CUTOVERS, static fn (string $flag): bool => FirstPartySession::current()), null);
    }

    /** What agents are offered: cutovers with no website bypass, and the MCP switches. */
    public static function forAgents(McpFeatureFlags $mcp): self
    {
        return new self(new ConfigDeploymentFlags(self::CUTOVERS), $mcp);
    }

    /** @return list<string> */
    public static function cutovers(): array
    {
        return array_keys(self::CUTOVERS);
    }

    public static function isMcpSwitch(string $flag): bool
    {
        return $flag === self::MCP || str_starts_with($flag, self::MCP_SWITCH);
    }

    public function enabled(string $flag): bool
    {
        if (self::isMcpSwitch($flag)) {
            if ($this->mcp === null) {
                return true;
            }

            return $flag === self::MCP
                ? $this->mcp->transportEnabled()
                : $this->mcp->switchedOn(substr($flag, strlen(self::MCP_SWITCH)));
        }

        return $this->cutovers->enabled($flag);
    }
}
