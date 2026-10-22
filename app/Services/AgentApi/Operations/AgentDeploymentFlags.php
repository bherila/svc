<?php

namespace App\Services\AgentApi\Operations;

use App\Services\Mcp\McpFeatureFlags;
use App\Services\Square\SquareConfiguration;
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
 * - **Square** is on only while a Square connection is configured and bound
 *   to a workspace (#438). It is not a cutover: nothing bypasses it, because
 *   without a credential there is nothing to call.
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

    /** The read-only Square pass-through: on only while Square is configured. */
    public const string SQUARE = 'square.connected';

    /** Product availability; first-party sessions never bypass it. */
    public const string TASK_ORGANIZATION = 'tasks.organization';

    /**
     * Portal invitations (#430), a product switch rather than an agent
     * cutover: `svc.portal_invitations.enabled` also governs the settings
     * page and sign-in. Listing needs only it; writes nest it inside the
     * client-write cutovers as well.
     */
    public const string PORTAL_INVITATIONS = 'svc.portal_invitations';

    public const string PORTAL_INVITATION_WRITES = 'agent.writes.portal_invitations';

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
        self::PORTAL_INVITATIONS => ['key' => 'svc.portal_invitations.enabled'],
        self::PORTAL_INVITATION_WRITES => ['key' => 'svc.portal_invitations.enabled', 'parents' => [self::CLIENTS]],
    ];

    /**
     * The environment variable behind each cutover's configuration key, as
     * the OpenAPI document names it in `x-svc-flags`.
     */
    private const array ENVIRONMENT = [
        self::WRITES => 'AGENT_API_WRITES_ENABLED',
        self::TIME_ENTRIES => 'AGENT_API_TIME_ENTRY_WRITES_ENABLED',
        self::INVOICES => 'AGENT_API_INVOICE_WRITES_ENABLED',
        self::PAYMENTS => 'AGENT_API_PAYMENT_WRITES_ENABLED',
        self::EXPENSES => 'AGENT_API_EXPENSE_WRITES_ENABLED',
        self::CLIENTS => 'AGENT_API_CLIENT_WRITES_ENABLED',
        self::PROJECTS => 'AGENT_API_PROJECT_WRITES_ENABLED',
        self::PROPOSALS => 'AGENT_API_PROPOSAL_WRITES_ENABLED',
        self::WORKSPACES => 'AGENT_API_WORKSPACE_WRITES_ENABLED',
        self::FILES => 'AGENT_API_FILE_WRITES_ENABLED',
        self::PORTAL_INVITATIONS => 'SVC_PORTAL_INVITATIONS_ENABLED',
        self::PORTAL_INVITATION_WRITES => 'SVC_PORTAL_INVITATIONS_ENABLED',
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

    /**
     * The environment switches an operation's declared flags depend on, each
     * cutover after the ones it nests inside (#459 item 6): what the document
     * lists as `x-svc-flags`. MCP switches and the Square precondition are not
     * installation cutovers and are left out.
     *
     * @param  list<string>  $flags
     * @return list<string>
     */
    public static function environmentSwitches(array $flags): array
    {
        $switches = [];
        foreach ($flags as $flag) {
            if ($flag === self::TASK_ORGANIZATION) {
                $switches[] = 'TASK_ORGANIZATION_WRITES_ENABLED';

                continue;
            }
            foreach (self::lineage($flag) as $cutover) {
                $switches[] = self::ENVIRONMENT[$cutover];
            }
        }

        return array_values(array_unique($switches));
    }

    /**
     * A cutover after every cutover it nests inside; nothing for a flag that
     * is not a cutover.
     *
     * @return list<string>
     */
    private static function lineage(string $flag): array
    {
        $declared = self::CUTOVERS[$flag] ?? null;
        if ($declared === null) {
            return [];
        }
        $parents = is_array($declared) ? ($declared['parents'] ?? []) : [];

        return [...array_merge(...array_map(self::lineage(...), $parents)), $flag];
    }

    public static function isMcpSwitch(string $flag): bool
    {
        return $flag === self::MCP || str_starts_with($flag, self::MCP_SWITCH);
    }

    public static function isAvailabilityPrecondition(string $flag): bool
    {
        return in_array($flag, [self::SQUARE, self::TASK_ORGANIZATION], true);
    }

    public function enabled(string $flag): bool
    {
        if ($flag === self::TASK_ORGANIZATION) {
            return config('task_organization.enabled') === true;
        }

        if ($flag === self::SQUARE) {
            return SquareConfiguration::fromConfig()->isConfigured();
        }
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
