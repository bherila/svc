<?php

namespace Tests\Unit\Mcp;

use App\Services\Mcp\Toolsets\McpToolsets;
use App\Services\Mcp\Toolsets\McpToolsetSelection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(McpToolsets::class)]
final class McpToolsetsTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>, string}> */
    public static function derivations(): iterable
    {
        yield 'a read and a write of one group' => ['projects.update', ['projects:write', 'projects:read'], 'projects'];
        yield 'the write wins over an earlier read of another group' => ['expense_schedules.create', ['clients:read', 'expenses:write'], 'expenses'];
        yield 'the first non-read scope wins' => ['x.y', ['a:read', 'b:approve', 'c:write'], 'b'];
        yield 'a non-read scope after reads' => ['x.y', ['a:read', 'b:read', 'c:write'], 'c'];
        yield 'only reads: the first' => ['task_planning.contacts.get', ['tasks:read', 'clients:read'], 'tasks'];
        yield 'a single read' => ['revenue.summary', ['revenue:read'], 'revenue'];
        yield 'a single non-read' => ['workspaces.create', ['workspaces:create'], 'workspaces'];
        yield 'a scope with no action' => ['x.y', ['plain'], 'plain'];
        yield 'a scope that merely contains read' => ['x.y', ['a:reader', 'b:write'], 'a'];
        yield 'no scope at all' => ['x.y', [], McpToolsets::GENERAL];
        yield 'an integration whole name' => ['square.sync', ['billing:write'], 'square'];
        yield 'an integration segment alone' => ['x.square', ['billing:read'], 'square'];
        yield 'the name is the integration' => ['square', ['billing:read'], 'square'];
        yield 'an integration prefix after an underscore' => ['invoices.square_link', ['billing:write', 'billing:read'], 'square'];
        yield 'an integration in the middle' => ['companies.square_customer.get', ['billing:read'], 'square'];
        yield 'a segment that only starts like it' => ['invoices.squares', ['billing:read'], 'billing'];
        yield 'a segment that contains it' => ['invoices.mysquare_link', ['billing:read'], 'billing'];
        yield 'a segment that continues without an underscore' => ['squarespace.list', ['billing:read'], 'billing'];
    }

    /** @param list<string> $scopes */
    #[DataProvider('derivations')]
    public function test_a_tool_belongs_to_the_group_of_its_defining_scope(string $tool, array $scopes, string $expected): void
    {
        $this->assertSame($expected, McpToolsets::toolsetFor($tool, $scopes));
    }

    public function test_toolsets_are_sorted_and_hold_their_sorted_tools(): void
    {
        $toolsets = McpToolsets::derive([
            'time_entries.log' => ['time:write'],
            'projects.list' => ['projects:read'],
            'time_entries.list' => ['time:read'],
            'projects.create' => ['projects:write', 'clients:read'],
            'square.sync' => ['billing:write'],
        ], []);

        $this->assertSame(['projects', 'square', 'time'], $toolsets->names());
        $this->assertSame(['projects.create', 'projects.list'], $toolsets->tools('projects'));
        $this->assertSame(['time_entries.list', 'time_entries.log'], $toolsets->tools('time'));
        $this->assertSame(['square.sync'], $toolsets->tools('square'));
        $this->assertSame('time', $toolsets->toolsetOf('time_entries.log'));
        $this->assertSame('square', $toolsets->toolsetOf('square.sync'));
        $this->assertNull($toolsets->toolsetOf('invoices.list'));
        $this->assertTrue($toolsets->has('projects'));
        $this->assertFalse($toolsets->has('billing'));
        $this->assertSame([], $toolsets->tools('billing'));
    }

    public function test_a_new_scope_group_becomes_a_toolset_with_no_other_change(): void
    {
        $toolsets = McpToolsets::derive([
            'widgets.make' => ['widgets:write', 'widgets:read'],
            'widgets.list' => ['widgets:read'],
        ], []);

        $this->assertSame(['widgets'], $toolsets->names());
        $this->assertSame(['widgets.list', 'widgets.make'], $toolsets->tools('widgets'));
        $this->assertSame('Tools gated by the widgets:* scopes.', $toolsets->description('widgets'));
    }

    public function test_nothing_authorized_is_no_toolset(): void
    {
        $toolsets = McpToolsets::derive([], ['billing:read' => 'Read invoices']);

        $this->assertSame([], $toolsets->names());
        $this->assertSame([], $toolsets->report(McpToolsetSelection::progressive()));
    }

    public function test_a_description_comes_from_the_groups_read_scope_first(): void
    {
        $descriptions = [
            'billing:write' => 'Write invoices',
            'billing:read' => 'Read invoices',
            'billing:deliver' => 'Send invoices',
            'projects' => 'Not a scope of the group',
            'projects:write' => 'Create projects',
            'projects:deliver' => 'Deliver projects',
            'tasks:read' => 'Read tasks',
        ];
        $toolsets = McpToolsets::derive([
            'invoices.list' => ['billing:read'],
            'projects.create' => ['projects:write'],
            'workspaces.create' => ['workspaces:create'],
            'tasks.list' => ['tasks:read'],
            'square.sync' => ['billing:write'],
        ], $descriptions);

        $this->assertSame('Read invoices', $toolsets->description('billing'));
        $this->assertSame('Create projects', $toolsets->description('projects'), 'With no read scope, the first scope of the group.');
        $this->assertSame('Tools gated by the workspaces:* scopes.', $toolsets->description('workspaces'));
        $this->assertSame('Read tasks', $toolsets->description('tasks'));
        $this->assertStringContainsString('Square', $toolsets->description('square'), 'Square is not described by the billing scopes it shares.');
        $this->assertNotSame('Read invoices', $toolsets->description('square'));
        $this->assertSame('', $toolsets->description('unknown'));
    }

    public function test_a_scope_whose_name_only_starts_like_the_group_does_not_describe_it(): void
    {
        $toolsets = McpToolsets::derive(['a.b' => ['time:write']], ['timesheet:read' => 'Read timesheets', 'time:write' => 'Log time']);

        $this->assertSame('Log time', $toolsets->description('time'));
    }

    public function test_the_report_counts_every_tool_and_marks_what_is_enabled(): void
    {
        $toolsets = McpToolsets::derive([
            'projects.list' => ['projects:read'],
            'projects.get' => ['projects:read'],
            'tasks.list' => ['tasks:read'],
        ], ['projects:read' => 'Read projects', 'tasks:read' => 'Read tasks']);

        $this->assertSame([
            ['name' => 'projects', 'description' => 'Read projects', 'tool_count' => 2, 'enabled' => true],
            ['name' => 'tasks', 'description' => 'Read tasks', 'tool_count' => 1, 'enabled' => false],
        ], $toolsets->report(McpToolsetSelection::progressive()->enable(['projects'])));
        $this->assertSame([true, true], array_column($toolsets->report(McpToolsetSelection::everything()), 'enabled'));
        $this->assertSame([false, false], array_column($toolsets->report(McpToolsetSelection::progressive()), 'enabled'));
    }
}
