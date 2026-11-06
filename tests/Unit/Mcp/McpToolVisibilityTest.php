<?php

namespace Tests\Unit\Mcp;

use App\Services\Mcp\Toolsets\McpToolsets;
use App\Services\Mcp\Toolsets\McpToolsetSelection;
use App\Services\Mcp\Toolsets\McpToolVisibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(McpToolVisibility::class)]
final class McpToolVisibilityTest extends TestCase
{
    private function visibility(): McpToolVisibility
    {
        return new McpToolVisibility(McpToolsets::derive([
            'projects.list' => ['projects:read'],
            'projects.get' => ['projects:read'],
            'time_entries.log' => ['time:write'],
            'invoices.list' => ['billing:read'],
            'invoices.get' => ['billing:read'],
            'tasks.list' => ['tasks:read'],
        ], []), ['context.get', 'projects.list', 'time_entries.log']);
    }

    public function test_the_core_set_is_orientation_the_toolset_tools_and_the_prompts_tools_once_each(): void
    {
        $this->assertSame(
            ['context.get', 'search', 'toolsets.list', 'toolsets.enable', 'toolsets.disable', 'projects.list', 'time_entries.log'],
            McpToolVisibility::core(['context.get', 'projects.list', 'time_entries.log', 'projects.list']),
        );
        $this->assertSame(['context.get', 'search', 'toolsets.list', 'toolsets.enable', 'toolsets.disable'], McpToolVisibility::core([]));
        $this->assertSame(['toolsets.list', 'toolsets.enable', 'toolsets.disable'], McpToolVisibility::TOOLSET_TOOLS);
        $this->assertSame('log-time-across-projects', McpToolVisibility::TIME_LOGGING_PROMPT);
    }

    public function test_only_the_core_set_is_visible_until_a_toolset_is_enabled(): void
    {
        $visibility = $this->visibility();
        $none = McpToolsetSelection::progressive();

        foreach (['context.get', 'projects.list', 'time_entries.log'] as $core) {
            $this->assertTrue($visibility->isVisible($core, $none), $core);
        }
        foreach (['projects.get', 'invoices.list', 'invoices.get', 'tasks.list'] as $other) {
            $this->assertFalse($visibility->isVisible($other, $none), $other);
        }
    }

    public function test_enabling_a_toolset_shows_its_tools_and_only_its_tools(): void
    {
        $visibility = $this->visibility();
        $billing = McpToolsetSelection::progressive()->enable(['billing']);

        $this->assertTrue($visibility->isVisible('invoices.list', $billing));
        $this->assertTrue($visibility->isVisible('invoices.get', $billing));
        $this->assertFalse($visibility->isVisible('projects.get', $billing));
        $this->assertFalse($visibility->isVisible('tasks.list', $billing));
        $this->assertTrue($visibility->isVisible('context.get', $billing), 'The core set stays.');

        $projects = $billing->disable(['billing'])->enable(['projects']);
        $this->assertTrue($visibility->isVisible('projects.get', $projects));
        $this->assertFalse($visibility->isVisible('invoices.list', $projects));
        $this->assertTrue($visibility->isVisible('projects.list', $projects->disable(['projects'])), 'A core tool outlives its toolset being disabled.');
    }

    public function test_a_tool_that_discovery_does_not_list_is_never_visible_unless_the_connection_takes_everything(): void
    {
        $visibility = $this->visibility();

        $this->assertFalse($visibility->isVisible('revenue.summary', McpToolsetSelection::progressive()->enable(['revenue', 'billing'])));
        $this->assertTrue($visibility->isVisible('revenue.summary', McpToolsetSelection::everything()), 'The registry decides what exists; this only decides what is shown of it.');
        $this->assertTrue($visibility->isVisible('invoices.get', McpToolsetSelection::everything()));
    }

    public function test_the_toolsets_needed_are_those_of_the_tools_not_yet_visible_each_once_in_order(): void
    {
        $visibility = $this->visibility();
        $tools = ['context.get', 'projects.get', 'invoices.get', 'projects.list', 'invoices.list', 'tasks.list'];

        $this->assertSame(['projects', 'billing', 'tasks'], $visibility->toolsetsNeededFor($tools, McpToolsetSelection::progressive()));
        $this->assertSame(['projects', 'tasks'], $visibility->toolsetsNeededFor($tools, McpToolsetSelection::progressive()->enable(['billing'])));
        $this->assertSame([], $visibility->toolsetsNeededFor($tools, McpToolsetSelection::progressive()->enable(['billing', 'projects', 'tasks'])));
        $this->assertSame([], $visibility->toolsetsNeededFor($tools, McpToolsetSelection::everything()));
        $this->assertSame([], $visibility->toolsetsNeededFor(['context.get', 'projects.list', 'time_entries.log'], McpToolsetSelection::progressive()));
        $this->assertSame([], $visibility->toolsetsNeededFor([], McpToolsetSelection::progressive()));
        $this->assertSame([], $visibility->toolsetsNeededFor(['not.a.tool'], McpToolsetSelection::progressive()), 'A tool in no toolset cannot be made visible by enabling one.');
    }
}
