<?php

namespace App\Support\AgentApi;

/**
 * The agent write cutovers, read in one place.
 *
 * Each flag withholds a write surface from OAuth principals - MCP and REST
 * clients - and nests inside `AGENT_API_WRITES_ENABLED` exactly as before
 * (#242). The signed-in website is not an agent: it passes every cutover, so an
 * operator who switches agent writes off has not also broken the web UI that
 * now runs on the same routes (#385). Roles, scopes and policies still apply to
 * it in full.
 */
final class AgentWriteCutover
{
    public static function writes(): bool
    {
        return FirstPartySession::current() || (bool) config('agent_api.writes_enabled');
    }

    public static function invoices(): bool
    {
        return FirstPartySession::current()
            || ((bool) config('agent_api.writes_enabled') && (bool) config('agent_api.invoice_writes_enabled'));
    }

    public static function payments(): bool
    {
        return FirstPartySession::current()
            || ((bool) config('agent_api.writes_enabled') && (bool) config('agent_api.payment_writes_enabled'));
    }

    public static function expenses(): bool
    {
        return FirstPartySession::current()
            || ((bool) config('agent_api.writes_enabled') && (bool) config('agent_api.expense_writes_enabled'));
    }

    public static function clients(): bool
    {
        return FirstPartySession::current()
            || ((bool) config('agent_api.writes_enabled') && (bool) config('agent_api.client_writes_enabled'));
    }

    public static function projects(): bool
    {
        return FirstPartySession::current()
            || ((bool) config('agent_api.writes_enabled') && (bool) config('agent_api.project_writes_enabled'));
    }

    public static function proposals(): bool
    {
        return FirstPartySession::current()
            || ((bool) config('agent_api.writes_enabled') && (bool) config('agent_api.proposal_writes_enabled'));
    }

    public static function workspaces(): bool
    {
        return FirstPartySession::current()
            || ((bool) config('agent_api.writes_enabled') && (bool) config('agent_api.workspace_writes_enabled'));
    }

    public static function files(): bool
    {
        return FirstPartySession::current()
            || ((bool) config('agent_api.writes_enabled') && (bool) config('agent_api.file_writes_enabled'));
    }

    public static function timeEntries(): bool
    {
        return FirstPartySession::current() || (bool) config('agent_api.time_entry_writes_enabled');
    }
}
