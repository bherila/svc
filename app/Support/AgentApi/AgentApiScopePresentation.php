<?php

namespace App\Support\AgentApi;

use BWH\Auth\OAuth\Credentials\ScopeGroup;
use BWH\Auth\OAuth\Credentials\ScopePresentation;
use BWH\Auth\OAuth\Credentials\ScopePreset;

/**
 * How the credential picker and the consent screen group SVC's agent scopes
 * (#582). Presentation only: a grant always stores explicit scope ids, "Admin"
 * is not an OAuth scope, and no preset may contain an Admin scope, so a future
 * sensitive scope never reaches an existing grant without a new consent.
 */
final class AgentApiScopePresentation implements ScopePresentation
{
    public const string ADMIN_DESCRIPTION = 'Send invoices and proposals to clients, record or reconcile money, accept agreements, approve time and rates, change clients, share files and create workspaces.';

    /**
     * The order the read-only preset lists its read scopes in. A `:read` scope
     * missing here follows them in catalog order.
     *
     * @var list<string>
     */
    private const array READ_ONLY_ORDER = [
        AgentApiScopes::IDENTITY_READ,
        AgentApiScopes::PROJECTS_READ,
        AgentApiScopes::TASKS_READ,
        AgentApiScopes::COLLABORATION_READ,
        AgentApiScopes::FILES_READ,
        AgentApiScopes::TIME_READ,
        AgentApiScopes::CLIENTS_READ,
        AgentApiScopes::PROPOSALS_READ,
        AgentApiScopes::BILLING_READ,
        AgentApiScopes::PAYMENTS_READ,
        AgentApiScopes::EXPENSES_READ,
        AgentApiScopes::FINANCE_READ,
    ];

    public function groups(): array
    {
        return [
            new ScopeGroup('account', 'Account', [AgentApiScopes::IDENTITY_READ, AgentApiScopes::MCP_USE]),
            new ScopeGroup('projects', 'Projects & tasks', [
                AgentApiScopes::PROJECTS_READ,
                AgentApiScopes::PROJECTS_WRITE,
                AgentApiScopes::TASKS_READ,
                AgentApiScopes::TASKS_WRITE,
            ]),
            new ScopeGroup('collaboration', 'Collaboration & files', [
                AgentApiScopes::COLLABORATION_READ,
                AgentApiScopes::COLLABORATION_WRITE,
                AgentApiScopes::FILES_READ,
            ]),
            new ScopeGroup('time', 'Time', [AgentApiScopes::TIME_READ, AgentApiScopes::TIME_WRITE]),
            new ScopeGroup('clients', 'Clients & proposals', [AgentApiScopes::CLIENTS_READ, AgentApiScopes::PROPOSALS_READ]),
            new ScopeGroup('billing', 'Billing & payments', [
                AgentApiScopes::BILLING_READ,
                AgentApiScopes::BILLING_WRITE,
                AgentApiScopes::PAYMENTS_READ,
            ]),
            new ScopeGroup('expenses', 'Expenses & finance', [
                AgentApiScopes::EXPENSES_READ,
                AgentApiScopes::EXPENSES_WRITE,
                AgentApiScopes::FINANCE_READ,
            ]),
            new ScopeGroup('admin', 'Admin', [
                AgentApiScopes::BILLING_DELIVER,
                AgentApiScopes::PAYMENTS_RECORD,
                AgentApiScopes::FINANCE_RECONCILE,
                AgentApiScopes::PROPOSALS_WRITE,
                AgentApiScopes::PROPOSALS_ACCEPT,
                AgentApiScopes::TIME_APPROVE,
                AgentApiScopes::CLIENTS_WRITE,
                AgentApiScopes::FILES_WRITE,
                AgentApiScopes::WORKSPACES_CREATE,
            ], true, self::ADMIN_DESCRIPTION),
        ];
    }

    public function presets(): array
    {
        $timeLogging = [
            AgentApiScopes::IDENTITY_READ,
            AgentApiScopes::PROJECTS_READ,
            AgentApiScopes::TASKS_READ,
            AgentApiScopes::TIME_READ,
            AgentApiScopes::TIME_WRITE,
        ];

        return [
            new ScopePreset(
                'read_only',
                'Read-only',
                'Read your projects, tasks, notes and discussions, files, time, clients, proposals, invoices, payments, expenses and finance. Changes nothing.',
                $this->readOnlyScopes(),
            ),
            new ScopePreset(
                'time_logging',
                'Time logging',
                'Read your projects and tasks, and log and manage your draft time against them.',
                $timeLogging,
            ),
            new ScopePreset(
                'project_work',
                'Project work',
                'Everything in Time logging, plus create and update tasks, read and post notes and discussions, and read attachments.',
                [
                    ...$timeLogging,
                    AgentApiScopes::TASKS_WRITE,
                    AgentApiScopes::COLLABORATION_READ,
                    AgentApiScopes::COLLABORATION_WRITE,
                    AgentApiScopes::FILES_READ,
                ],
            ),
            new ScopePreset(
                'invoice_drafting',
                'Invoice drafting',
                'Read projects, clients, time and received payments, and create or update invoice drafts. Cannot issue or send them.',
                [
                    AgentApiScopes::IDENTITY_READ,
                    AgentApiScopes::PROJECTS_READ,
                    AgentApiScopes::CLIENTS_READ,
                    AgentApiScopes::TIME_READ,
                    AgentApiScopes::BILLING_READ,
                    AgentApiScopes::BILLING_WRITE,
                    AgentApiScopes::PAYMENTS_READ,
                ],
            ),
        ];
    }

    /**
     * identity:read plus every catalog scope ending in `:read`, so a new read
     * scope joins the preset on its own.
     *
     * @return list<string>
     */
    private function readOnlyScopes(): array
    {
        $reads = [AgentApiScopes::IDENTITY_READ];
        foreach (array_keys(AgentApiScopes::descriptions()) as $scope) {
            if (str_ends_with($scope, ':read')) {
                $reads[] = $scope;
            }
        }
        $reads = array_values(array_unique($reads));

        $ordered = array_values(array_filter(self::READ_ONLY_ORDER, static fn (string $scope): bool => in_array($scope, $reads, true)));

        return [...$ordered, ...array_values(array_diff($reads, $ordered))];
    }
}
