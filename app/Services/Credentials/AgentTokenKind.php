<?php

namespace App\Services\Credentials;

/**
 * Every way SVC itself mints a Passport personal access token for an agent
 * principal, as opposed to one a person creates on the setup page or an OAuth
 * connector obtains through consent.
 *
 * Each kind stores its tokens under its own name prefix, so a revocation by
 * name can only ever select a token that kind issued - never a person's own
 * credential or an OAuth-connected agent's that happens to share the name -
 * and names the actor its audit entries carry.
 */
enum AgentTokenKind
{
    /** `svc:auth:issue-agent-token`: an operator acting as one exact person's agent. */
    case Operator;

    /** `svc:mcp:deploy-smoke-credentials`: the post-deploy MCP smoke's data-blind principal. */
    case DeploymentSmoke;

    /** `scripts/mcp-smoke-credentials.php`: the pre-merge smoke on a disposable database. */
    case CiSmoke;

    /**
     * Stored before the label, as the setup page's personal tokens carry their
     * own prefix. The smoke prefixes keep the names those tokens always had.
     */
    public function namePrefix(): string
    {
        return match ($this) {
            self::Operator => 'operator-agent-token: ',
            self::DeploymentSmoke => 'Deployment MCP smoke ',
            self::CiSmoke => 'SVC MCP smoke ',
        };
    }

    /** The `actor` an audit entry about this kind's tokens records. */
    public function actor(): string
    {
        return match ($this) {
            self::Operator => 'console',
            self::DeploymentSmoke => 'deploy-smoke',
            self::CiSmoke => 'ci-smoke',
        };
    }
}
