<?php

namespace App\Support\AgentApi;

final class AgentApiScopes
{
    public const string IDENTITY_READ = 'identity:read';

    public const string PROJECTS_READ = 'projects:read';

    public const string TASKS_READ = 'tasks:read';

    public const string TASKS_WRITE = 'tasks:write';

    public const string TIME_READ = 'time:read';

    public const string TIME_WRITE = 'time:write';

    public const string TIME_APPROVE = 'time:approve';

    public const string BILLING_READ = 'billing:read';

    public const string BILLING_WRITE = 'billing:write';

    public const string BILLING_DELIVER = 'billing:deliver';

    public const string MCP_USE = 'mcp:use';

    /** @return array<string, string> */
    public static function descriptions(): array
    {
        return [
            self::IDENTITY_READ => 'Read your SVC identity and available workspaces',
            self::PROJECTS_READ => 'Read authorized projects',
            self::TASKS_READ => 'Read authorized tasks',
            self::TASKS_WRITE => 'Create and update authorized tasks',
            self::TIME_READ => 'Read authorized time entries',
            self::TIME_WRITE => 'Log and manage authorized draft time',
            self::TIME_APPROVE => 'Approve authorized project time',
            self::BILLING_READ => 'Read authorized invoices',
            self::BILLING_WRITE => 'Create and update authorized invoice drafts',
            self::BILLING_DELIVER => 'Issue, send, and void authorized invoices',
            self::MCP_USE => 'Connect to SVC through MCP',
        ];
    }
}
