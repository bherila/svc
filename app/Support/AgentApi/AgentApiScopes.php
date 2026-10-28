<?php

namespace App\Support\AgentApi;

final class AgentApiScopes
{
    public const string IDENTITY_READ = 'identity:read';

    public const string PROJECTS_READ = 'projects:read';

    public const string PROJECTS_WRITE = 'projects:write';

    public const string COLLABORATION_READ = 'collaboration:read';

    public const string COLLABORATION_WRITE = 'collaboration:write';

    public const string TASKS_READ = 'tasks:read';

    public const string TASKS_WRITE = 'tasks:write';

    public const string TIME_READ = 'time:read';

    public const string TIME_WRITE = 'time:write';

    public const string TIME_APPROVE = 'time:approve';

    public const string BILLING_READ = 'billing:read';

    public const string BILLING_WRITE = 'billing:write';

    public const string BILLING_DELIVER = 'billing:deliver';

    public const string EXPENSES_READ = 'expenses:read';

    public const string EXPENSES_WRITE = 'expenses:write';

    public const string PAYMENTS_READ = 'payments:read';

    public const string PAYMENTS_RECORD = 'payments:record';

    /** The finance reconciliation surface (#457); a Sanctum finance.read token holds it there. */
    public const string FINANCE_READ = 'finance:read';

    /** The finance reconciliation surface (#457); a Sanctum finance.reconcile token holds it there. */
    public const string FINANCE_RECONCILE = 'finance:reconcile';

    public const string CLIENTS_READ = 'clients:read';

    public const string CLIENTS_WRITE = 'clients:write';

    public const string PROPOSALS_READ = 'proposals:read';

    public const string PROPOSALS_WRITE = 'proposals:write';

    public const string PROPOSALS_ACCEPT = 'proposals:accept';

    public const string WORKSPACES_CREATE = 'workspaces:create';

    public const string FILES_READ = 'files:read';

    public const string FILES_WRITE = 'files:write';

    public const string MCP_USE = 'mcp:use';

    /**
     * The REST resource's scope ceiling (`/api/v1`): every operation scope, and
     * never the MCP connection scope, which opens nothing on REST.
     *
     * @return list<string>
     */
    public static function rest(): array
    {
        $scopes = [];
        foreach (array_keys(self::descriptions()) as $scope) {
            if ($scope !== self::MCP_USE) {
                $scopes[] = $scope;
            }
        }

        return $scopes;
    }

    /**
     * The MCP resource's scope ceiling (`/api/v1/mcp`): the connection scope plus
     * the operation scopes its tools, resources and prompts use - today every one,
     * since each operation scope gates at least one MCP capability (pinned by
     * AgentOAuthResourceCeilingTest against the operation registry).
     *
     * @return list<string>
     */
    public static function mcp(): array
    {
        return [self::MCP_USE, ...self::rest()];
    }

    /** @return array<string, string> */
    public static function descriptions(): array
    {
        return [
            self::IDENTITY_READ => 'Read your SVC identity and available workspaces',
            self::PROJECTS_READ => 'Read authorized projects',
            self::PROJECTS_WRITE => 'Create, edit and archive projects and manage project member access as a workspace owner or admin',
            self::COLLABORATION_READ => 'Read authorized native notes, discussions and comments; portal identities receive only client-visible content',
            self::COLLABORATION_WRITE => 'Create, edit, publish or remove native collaboration within current staff roles; never grants access or sends mail',
            self::TASKS_READ => 'Read authorized tasks',
            self::TASKS_WRITE => 'Create and update authorized tasks',
            self::TIME_READ => 'Read authorized time entries',
            self::TIME_WRITE => 'Log and manage authorized draft time',
            self::TIME_APPROVE => 'Set billing rates and approve authorized project time',
            self::BILLING_READ => 'Read authorized invoices',
            self::BILLING_WRITE => 'Create and update authorized invoice drafts',
            self::BILLING_DELIVER => 'Issue, send, correct, and void authorized invoices',
            self::EXPENSES_READ => 'Read authorized expenses',
            self::EXPENSES_WRITE => 'Record, edit and delete draft expenses as a workspace manager',
            self::PAYMENTS_READ => 'Read received payments on authorized invoices',
            self::PAYMENTS_RECORD => 'Record money already received and correct a recorded payment\'s method, reference or date; never initiate a charge',
            self::FINANCE_READ => 'Read received payments with their finance reconciliation allocations, and the processor payouts that settled them, as a workspace owner or admin',
            self::FINANCE_RECONCILE => 'Record and withdraw finance reconciliation allocations against received payments as a workspace owner or admin; never moves money',
            self::CLIENTS_READ => 'Read client companies as a workspace manager',
            self::CLIENTS_WRITE => 'Create, edit and archive client companies and their agreements as a workspace manager',
            self::PROPOSALS_READ => 'Read authorized proposals and their commercial terms',
            self::PROPOSALS_WRITE => 'Create and send proposals as a workspace manager',
            self::PROPOSALS_ACCEPT => 'Accept authorized proposals and sign their resulting agreement',
            self::WORKSPACES_CREATE => 'Create new workspaces you own',
            self::FILES_READ => 'Read authorized attachments; legacy generic routes require a workspace manager and collaboration routes require exact parent access',
            self::FILES_WRITE => 'Upload, share and delete authorized files; sharing requires current publisher authority and new uploads start private',
            self::MCP_USE => 'Connect to SVC through MCP',
        ];
    }
}
