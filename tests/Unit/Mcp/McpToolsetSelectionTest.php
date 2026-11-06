<?php

namespace Tests\Unit\Mcp;

use App\Services\Mcp\Toolsets\McpToolsetSelection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(McpToolsetSelection::class)]
final class McpToolsetSelectionTest extends TestCase
{
    /** @return iterable<string, array{?string, bool}> */
    public static function headers(): iterable
    {
        yield 'all' => ['all', true];
        yield 'upper case' => ['ALL', true];
        yield 'mixed case with spaces' => ['  All ', true];
        yield 'absent' => [null, false];
        yield 'empty' => ['', false];
        yield 'a longer word' => ['everything', false];
        yield 'a prefix' => ['al', false];
        yield 'a list' => ['all,projects', false];
        yield 'progressive' => ['progressive', false];
    }

    #[DataProvider('headers')]
    public function test_only_the_value_all_opts_into_the_full_list(?string $header, bool $everything): void
    {
        $selection = McpToolsetSelection::fromConnectHeader($header);

        $this->assertSame($everything, $selection->all);
        $this->assertSame([], $selection->enabled);
    }

    public function test_progressive_enables_nothing_and_everything_includes_every_toolset(): void
    {
        $this->assertFalse(McpToolsetSelection::progressive()->all);
        $this->assertFalse(McpToolsetSelection::progressive()->includes('projects'));
        $this->assertTrue(McpToolsetSelection::everything()->includes('projects'));
        $this->assertTrue(McpToolsetSelection::everything()->includes('anything at all'));
    }

    public function test_a_session_with_nothing_stored_predates_progressive_discovery_and_lists_everything(): void
    {
        $this->assertTrue(McpToolsetSelection::fromSession(null)->all);
    }

    /** @return iterable<string, array{mixed, bool, list<string>}> */
    public static function stored(): iterable
    {
        yield 'a string' => ['all', false, []];
        yield 'an integer' => [1, false, []];
        yield 'false' => [false, false, []];
        yield 'an empty array' => [[], false, []];
        yield 'all true' => [['all' => true, 'enabled' => []], true, []];
        yield 'all true with names' => [['all' => true, 'enabled' => ['projects']], true, []];
        yield 'all as a string' => [['all' => 'true', 'enabled' => ['projects']], false, ['projects']];
        yield 'all as one' => [['all' => 1], false, []];
        yield 'all false' => [['all' => false, 'enabled' => ['time', 'billing']], false, ['time', 'billing']];
        yield 'duplicates dropped' => [['all' => false, 'enabled' => ['time', 'billing', 'time']], false, ['time', 'billing']];
        yield 'non-strings and empties dropped' => [['enabled' => ['time', 5, '', null, ['x'], 'billing']], false, ['time', 'billing']];
        yield 'enabled not an array' => [['enabled' => 'time'], false, []];
        yield 'enabled absent' => [['all' => false], false, []];
    }

    /**
     * @param  list<string>  $enabled
     */
    #[DataProvider('stored')]
    public function test_a_stored_selection_is_read_back_defensively(mixed $stored, bool $all, array $enabled): void
    {
        $selection = McpToolsetSelection::fromSession($stored);

        $this->assertSame($all, $selection->all);
        $this->assertSame($enabled, $selection->enabled);
    }

    public function test_a_selection_survives_a_round_trip_through_the_session(): void
    {
        $selection = McpToolsetSelection::progressive()->enable(['time', 'billing']);

        $this->assertSame(['all' => false, 'enabled' => ['time', 'billing']], $selection->toSession());
        $this->assertSame(['all' => false, 'enabled' => ['time', 'billing']], McpToolsetSelection::fromSession($selection->toSession())->toSession());
        $this->assertSame(['all' => true, 'enabled' => []], McpToolsetSelection::everything()->toSession());
    }

    public function test_enabling_adds_in_order_without_repeats_and_leaves_the_original_alone(): void
    {
        $none = McpToolsetSelection::progressive();
        $one = $none->enable(['time']);
        $three = $one->enable(['billing', 'time', 'projects', 'billing']);

        $this->assertSame([], $none->enabled);
        $this->assertSame(['time'], $one->enabled);
        $this->assertSame(['time', 'billing', 'projects'], $three->enabled);
        $this->assertTrue($three->includes('billing'));
        $this->assertFalse($three->includes('tasks'));
        $this->assertFalse($three->includes('bill'));
        $this->assertSame([], $none->enable([])->enabled);
    }

    public function test_disabling_removes_only_the_named_toolsets_and_keeps_the_list_a_list(): void
    {
        $three = McpToolsetSelection::progressive()->enable(['time', 'billing', 'projects']);

        $this->assertSame(['time', 'projects'], $three->disable(['billing'])->enabled);
        $this->assertFalse($three->disable(['billing'])->all);
        $this->assertFalse($three->enable(['tasks'])->all);
        $this->assertSame([0, 1], array_keys($three->disable(['time'])->enabled));
        $this->assertSame(['projects'], $three->disable(['billing', 'time', 'unknown'])->enabled);
        $this->assertSame(['time', 'billing', 'projects'], $three->disable(['tasks'])->enabled);
        $this->assertSame([], $three->disable(['time', 'billing', 'projects'])->enabled);
        $this->assertSame(['time', 'billing', 'projects'], $three->enabled);
    }

    public function test_a_full_list_connection_neither_enables_nor_disables_anything(): void
    {
        $everything = McpToolsetSelection::everything();

        $this->assertSame($everything, $everything->enable(['projects']));
        $this->assertSame($everything, $everything->disable(['projects']));
        $this->assertTrue($everything->enable(['projects'])->all);
    }
}
