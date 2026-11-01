<?php

namespace Tests\Unit\AgentApi;

use App\Support\AgentApi\AgentApiScopePresentation;
use App\Support\AgentApi\AgentApiScopes as S;
use BWH\Auth\OAuth\Credentials\DeclaresScopeImplications;
use BWH\Auth\OAuth\Credentials\ScopePresentation;
use BWH\Auth\OAuth\Credentials\ScopePresentationValidator;
use Tests\TestCase;

/**
 * The credential picker's and consent screen's grouping of SVC's agent scopes
 * (#582). Pinned exactly, so changing a group or preset is a deliberate edit
 * that the owner sees.
 */
final class AgentApiScopePresentationTest extends TestCase
{
    private AgentApiScopePresentation $presentation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->presentation = new AgentApiScopePresentation;
    }

    public function test_every_catalog_scope_is_in_exactly_one_group(): void
    {
        $placed = [];
        foreach ($this->presentation->groups() as $group) {
            foreach ($group->scopes as $scope) {
                $placed[$scope] = ($placed[$scope] ?? 0) + 1;
            }
        }

        foreach (array_keys(S::descriptions()) as $scope) {
            $this->assertSame(1, $placed[$scope] ?? 0, "Scope {$scope} must be in exactly one group.");
        }
        $this->assertEqualsCanonicalizing(array_keys(S::descriptions()), array_keys($placed));
    }

    public function test_no_preset_contains_an_admin_scope(): void
    {
        $admin = $this->scopesOf('admin');
        $this->assertNotSame([], $admin);

        foreach ($this->presentation->presets() as $preset) {
            $this->assertSame([], array_values(array_intersect($preset->scopes, $admin)), "Preset {$preset->id} contains an Admin scope.");
        }
    }

    public function test_read_only_is_identity_read_plus_every_read_scope(): void
    {
        $expected = array_values(array_filter(
            array_keys(S::descriptions()),
            static fn (string $scope): bool => $scope === S::IDENTITY_READ || str_ends_with($scope, ':read'),
        ));
        $actual = $this->presetScopes('read_only');

        $this->assertEqualsCanonicalizing($expected, $actual);
        $this->assertSame(array_values(array_unique($actual)), $actual);
    }

    public function test_the_groups_are_pinned_in_order(): void
    {
        $groups = array_map(
            static fn ($group): array => [$group->id, $group->label, $group->sensitive, $group->scopes],
            $this->presentation->groups(),
        );

        $this->assertSame([
            ['account', 'Account', false, [S::IDENTITY_READ, S::MCP_USE]],
            ['projects', 'Projects & tasks', false, [S::PROJECTS_READ, S::PROJECTS_WRITE, S::TASKS_READ, S::TASKS_WRITE]],
            ['collaboration', 'Collaboration & files', false, [S::COLLABORATION_READ, S::COLLABORATION_WRITE, S::FILES_READ]],
            ['time', 'Time', false, [S::TIME_READ, S::TIME_WRITE]],
            ['clients', 'Clients & proposals', false, [S::CLIENTS_READ, S::PROPOSALS_READ]],
            ['billing', 'Billing & payments', false, [S::BILLING_READ, S::BILLING_WRITE, S::PAYMENTS_READ]],
            ['expenses', 'Expenses & finance', false, [S::EXPENSES_READ, S::EXPENSES_WRITE, S::FINANCE_READ]],
            ['admin', 'Admin', true, [
                S::BILLING_DELIVER, S::PAYMENTS_RECORD, S::FINANCE_RECONCILE, S::PROPOSALS_WRITE, S::PROPOSALS_ACCEPT,
                S::TIME_APPROVE, S::CLIENTS_WRITE, S::FILES_WRITE, S::WORKSPACES_CREATE,
            ]],
        ], $groups);

        $descriptions = array_map(static fn ($group): ?string => $group->description, $this->presentation->groups());
        $this->assertSame(
            [null, null, null, null, null, null, null, 'Send invoices and proposals to clients, record or reconcile money, accept agreements, approve time and rates, change clients, share files and create workspaces.'],
            $descriptions,
        );
    }

    public function test_the_presets_are_pinned_in_order(): void
    {
        $presets = array_map(
            static fn ($preset): array => [$preset->id, $preset->label, $preset->description, $preset->scopes],
            $this->presentation->presets(),
        );

        $this->assertSame([
            ['read_only', 'Read-only', 'Read your projects, tasks, notes and discussions, files, time, clients, proposals, invoices, payments, expenses and finance. Changes nothing.', [
                S::IDENTITY_READ, S::PROJECTS_READ, S::TASKS_READ, S::COLLABORATION_READ, S::FILES_READ, S::TIME_READ,
                S::CLIENTS_READ, S::PROPOSALS_READ, S::BILLING_READ, S::PAYMENTS_READ, S::EXPENSES_READ, S::FINANCE_READ,
            ]],
            ['time_logging', 'Time logging', 'Read your projects and tasks, and log and manage your draft time against them.', [
                S::IDENTITY_READ, S::PROJECTS_READ, S::TASKS_READ, S::TIME_READ, S::TIME_WRITE,
            ]],
            ['project_work', 'Project work', 'Everything in Time logging, plus create and update tasks, read and post notes and discussions, and read attachments.', [
                S::IDENTITY_READ, S::PROJECTS_READ, S::TASKS_READ, S::TIME_READ, S::TIME_WRITE,
                S::TASKS_WRITE, S::COLLABORATION_READ, S::COLLABORATION_WRITE, S::FILES_READ,
            ]],
            ['invoice_drafting', 'Invoice drafting', 'Read projects, clients, time and received payments, and create or update invoice drafts. Cannot issue or send them.', [
                S::IDENTITY_READ, S::PROJECTS_READ, S::CLIENTS_READ, S::TIME_READ, S::BILLING_READ, S::BILLING_WRITE, S::PAYMENTS_READ,
            ]],
        ], $presets);
    }

    public function test_the_implications_are_pinned(): void
    {
        $this->assertSame([
            S::PROJECTS_WRITE => [S::PROJECTS_READ],
            S::TASKS_WRITE => [S::TASKS_READ],
            S::COLLABORATION_WRITE => [S::COLLABORATION_READ],
            S::TIME_WRITE => [S::TIME_READ],
            S::BILLING_WRITE => [S::BILLING_READ],
            S::EXPENSES_WRITE => [S::EXPENSES_READ],
            S::BILLING_DELIVER => [S::BILLING_READ],
            S::PAYMENTS_RECORD => [S::PAYMENTS_READ],
            S::FINANCE_RECONCILE => [S::FINANCE_READ],
            S::PROPOSALS_WRITE => [S::PROPOSALS_READ],
            S::PROPOSALS_ACCEPT => [S::PROPOSALS_READ],
            S::TIME_APPROVE => [S::TIME_READ],
            S::CLIENTS_WRITE => [S::CLIENTS_READ],
            S::FILES_WRITE => [S::FILES_READ],
        ], $this->presentation->implications());
    }

    public function test_every_preset_is_closed_under_the_implications(): void
    {
        $this->assertInstanceOf(DeclaresScopeImplications::class, $this->app->make(ScopePresentation::class));
        foreach ($this->presentation->presets() as $preset) {
            foreach ($preset->scopes as $scope) {
                foreach ($this->presentation->implications()[$scope] ?? [] as $required) {
                    $this->assertContains($required, $preset->scopes, "Preset {$preset->id} has {$scope} without {$required}.");
                }
            }
        }
    }

    public function test_the_package_validator_accepts_the_catalog(): void
    {
        ScopePresentationValidator::assertValid($this->presentation);
        ScopePresentationValidator::assertValid($this->app->make(ScopePresentation::class));
        $this->assertInstanceOf(AgentApiScopePresentation::class, $this->app->make(ScopePresentation::class));
    }

    /** @return list<string> */
    private function scopesOf(string $groupId): array
    {
        foreach ($this->presentation->groups() as $group) {
            if ($group->id === $groupId) {
                return $group->scopes;
            }
        }
        $this->fail("No group {$groupId}.");
    }

    /** @return list<string> */
    private function presetScopes(string $presetId): array
    {
        foreach ($this->presentation->presets() as $preset) {
            if ($preset->id === $presetId) {
                return $preset->scopes;
            }
        }
        $this->fail("No preset {$presetId}.");
    }
}
