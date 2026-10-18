<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\FindsUserByPublicId;
use App\Services\Credentials\AgentTokenIssuer;
use App\Services\Credentials\AgentTokenKind;
use App\Services\Credentials\AgentTokenRefused;
use App\Services\Credentials\AgentTokenRequest;
use App\Services\Credentials\InvalidAgentTokenRequest;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Issue a short-lived agent (REST or MCP) access token for one exact person,
 * from a shell, for an operator who needs to act as an agent would without
 * walking a browser consent flow. {@see AgentTokenIssuer} owns the issue and
 * its audit; this only reads the shell's arguments and prints the result once.
 */
final class IssueAgentTokenCommand extends Command
{
    use FindsUserByPublicId;

    protected $signature = 'svc:auth:issue-agent-token
        {user_public_id : Exact user public UUID}
        {name : Name for the agent token}
        {scopes* : Agent scopes; include mcp:use for an MCP token, omit it for a REST token}
        {--ttl=60 : Minutes the token remains valid, at most 240}';

    protected $description = 'Issue a short-lived, scope-limited agent (REST or MCP) access token for one exact user';

    public function handle(AgentTokenIssuer $issuer): int
    {
        $ttl = AgentTokenRequest::ttlFromInput((string) $this->option('ttl'));
        try {
            $request = AgentTokenRequest::of(
                AgentTokenKind::Operator,
                (string) $this->argument('name'),
                (array) $this->argument('scopes'),
                // Checked below, after the name and scopes it reports before.
                $ttl ?? AgentTokenRequest::MAX_TTL_MINUTES,
            );
        } catch (InvalidAgentTokenRequest $invalid) {
            $this->error($invalid->getMessage());

            return self::INVALID;
        }
        if ($ttl === null) {
            $this->error('--ttl must be a whole number of minutes from 1 to '.AgentTokenRequest::MAX_TTL_MINUTES.'.');

            return self::INVALID;
        }

        $user = $this->userByPublicId((string) $this->argument('user_public_id'));
        if (is_int($user)) {
            return $user;
        }

        try {
            $issued = $issuer->issue($user, $request);
        } catch (AgentTokenRefused $refused) {
            $this->error($refused->getMessage());

            return self::FAILURE;
        }

        // Raw: the console formatter would otherwise read markup in the name
        // and strip it or splice escape codes into the JSON.
        $this->output->writeln((string) json_encode([
            'token' => $issued->token(),
            'expires_at' => $issued->expiresAt->toIso8601String(),
            'name' => $issued->label,
            'scopes' => $issued->scopes,
            'resource' => $issued->resource,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
