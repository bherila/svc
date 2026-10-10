<?php

namespace Tests\Unit\Mcp;

use App\Services\Mcp\McpFeatureFlags;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class McpFeatureFlagsTest extends TestCase
{
    /** @return iterable<string, array{string, string, array<string, mixed>, bool}> */
    public static function switches(): iterable
    {
        foreach (['projects.list' => 'mcp.read', 'time_entries.approve' => 'mcp.write'] as $name => $group) {
            foreach ([
                'group enabled tool disabled' => [[$group => true, $name => false], false],
                'group disabled tool enabled' => [[$group => false, $name => true], false],
                'both disabled' => [[$group => false, $name => false], false],
                'both enabled' => [[$group => true, $name => true], true],
                'tool disabled' => [[$name => false], false],
                'tool enabled' => [[$name => true], true],
                'group disabled' => [[$group => false], false],
                'group enabled' => [[$group => true], true],
                'defaults' => [[], true],
                'nonboolean group' => [[$group => 'true', $name => true], false],
                'nonboolean tool' => [[$group => true, $name => 'true'], false],
            ] as $case => [$flags, $expected]) {
                yield $name.' '.$case => [$name, $group, $flags, $expected];
            }
        }
    }

    /** @param array<string, mixed> $flags */
    #[DataProvider('switches')]
    public function test_neither_group_nor_tool_can_override_the_other_kill_switch(string $name, string $group, array $flags, bool $enabled): void
    {
        config(['agent_api.mcp_enabled' => true, 'agent_api.mcp_feature_flags' => $flags]);
        $this->assertSame($enabled, app(McpFeatureFlags::class)->enabledFor($name, $group));
        config(['agent_api.mcp_enabled' => false]);
        $this->assertFalse(app(McpFeatureFlags::class)->enabledFor($name, $group));
    }

    public function test_the_transport_and_single_switches_read_configuration_on_every_call(): void
    {
        $flags = app(McpFeatureFlags::class);
        config(['agent_api.mcp_enabled' => true, 'agent_api.mcp_feature_flags' => ['mcp.read' => false, 'invoices.get' => 'true']]);
        $this->assertTrue($flags->transportEnabled());
        $this->assertFalse($flags->switchedOn('mcp.read'));
        $this->assertFalse($flags->switchedOn('invoices.get'), 'Only true counts as on');
        $this->assertTrue($flags->switchedOn('projects.list'), 'An absent key is on');

        config(['agent_api.mcp_enabled' => false, 'agent_api.mcp_feature_flags' => 'not a list']);
        $this->assertFalse($flags->transportEnabled());
        $this->assertTrue($flags->switchedOn('mcp.read'), 'A malformed switch list withholds nothing by itself');
    }
}
