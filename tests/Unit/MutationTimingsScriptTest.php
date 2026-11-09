<?php

namespace Tests\Unit;

use Infection\Logger\MutationAnalysis\TeamCity\MessageName;
use Infection\Logger\MutationAnalysis\TeamCity\TeamCity;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The timing report reads the `--teamcity` stream Infection writes as each
 * mutant finishes. The fixture is written with Infection's own TeamCity
 * writer, so its escaping and message shapes are the pinned version's.
 */
#[CoversNothing]
final class MutationTimingsScriptTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2).'/scripts/mutation-timings.php';
    }

    public function test_a_partial_log_ranks_the_finished_mutants_and_names_the_ones_still_running(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'mutation-timings');
        $this->assertIsString($source);
        file_put_contents($source, "<?php\n\nfunction first(): int\n{\n    return 1;\n}\n");
        $offset = (int) strpos((string) file_get_contents($source), 'return 1');

        $log = implode('', [
            $this->write(MessageName::TEST_COUNT, ['count' => 3]),
            $this->write(MessageName::TEST_SUITE_STARTED, ['name' => $source, 'nodeId' => 's1', 'parentNodeId' => '0']),
            ...$this->killed('n1', 'Infection\Mutator\Number\OneZero (aaa)', $source, $offset, 1520),
            ...$this->escaped('n2', "Infection\\Mutator\\Return_\\IntegerNegation (b'b|b)", 'infection:///absent/app/Gone.php::40-52', 23400),
            $this->write(MessageName::TEST_STARTED, ['name' => 'Infection\Mutator\Arithmetic\Plus (ccc)', 'nodeId' => 'n3', 'parentNodeId' => 's1', 'locationHint' => 'infection:///absent/app/Slow.php::7-9']),
        ]);

        $report = mutation_timings_report($log, 5);
        unlink($source);

        $this->assertStringContainsString('2 mutants finished, 1 still running when the log ends; 24.9s of mutant process time in total.', $report);
        $this->assertStringContainsString('escaped: 1, killed by tests: 1', $report);
        // Slowest first; quotes and pipes in a name survive TeamCity escaping.
        $this->assertLessThan(strpos($report, '| 1.5 |'), strpos($report, "| 23.4 | escaped | Infection\\Mutator\\Return_\\IntegerNegation (b'b|b)"));
        // A readable file is reported by line; an absent one keeps its byte offset.
        $this->assertStringContainsString(basename($source).':5`', $report);
        $this->assertStringContainsString('`/absent/app/Gone.php@40`', $report);
        // Time is attributed to source files, and every mutant that was not
        // killed is listed, however fast it was.
        $this->assertStringContainsString('| 23.4 | 1 | `/absent/app/Gone.php` |', $report);
        $this->assertStringContainsString("Not killed (1), each needs an assertion or a recorded reason:\n\n- escaped: Infection\\Mutator\\Return_\\IntegerNegation (b'b|b) `/absent/app/Gone.php@40`", $report);
        $this->assertStringContainsString("Still running when the log ends:\n\n- Infection\\Mutator\\Arithmetic\\Plus (ccc) `/absent/app/Slow.php@7`", $report);
    }

    public function test_a_mutator_wrapped_by_a_global_ignore_is_reported_by_its_own_name(): void
    {
        $wrapped = 'Infection\\Mutator\\IgnoreMutator (ddd)';
        $log = implode('', [
            $this->write(MessageName::TEST_STARTED, ['name' => $wrapped, 'nodeId' => 'n1', 'parentNodeId' => 's1', 'locationHint' => 'infection:///absent/app/A.php::1-2']),
            $this->write(MessageName::TEST_FAILED, ['name' => $wrapped, 'nodeId' => 'n1', 'message' => "Mutator: ArrayItem\nMutation ID: ddd\nMutation result: escaped", 'duration' => 100]),
        ]);

        $report = mutation_timings_report($log);

        $this->assertStringContainsString('| 0.1 | escaped | ArrayItem (ddd) |', $report);
        $this->assertStringNotContainsString('IgnoreMutator', $report);
    }

    public function test_an_empty_log_reports_nothing_measured_and_an_unreadable_one_is_unusable(): void
    {
        $this->assertStringContainsString('0 mutants finished, 0 still running', mutation_timings_report(''));
        $this->assertSame(2, mutation_timings_main(['mutation-timings.php', '/absent/mutants.teamcity.log']));
    }

    /** @param array<string, string|int> $attributes */
    private function write(MessageName $name, array $attributes): string
    {
        $writer = (new ReflectionClass(TeamCity::class))->newInstanceWithoutConstructor();

        return $writer->write($name, $attributes);
    }

    /** @return list<string> */
    private function killed(string $node, string $name, string $path, int $offset, int $ms): array
    {
        return [
            $this->write(MessageName::TEST_STARTED, ['name' => $name, 'nodeId' => $node, 'parentNodeId' => 's1', 'locationHint' => "infection://{$path}::{$offset}-".($offset + 8)]),
            $this->write(MessageName::TEST_STDOUT, ['name' => $name, 'out' => "Mutator: OneZero\nMutation ID: aaa\nMutation result: killed by tests"]),
            $this->write(MessageName::TEST_FINISHED, ['name' => $name, 'nodeId' => $node, 'duration' => $ms]),
        ];
    }

    /** @return list<string> */
    private function escaped(string $node, string $name, string $location, int $ms): array
    {
        return [
            $this->write(MessageName::TEST_STARTED, ['name' => $name, 'nodeId' => $node, 'parentNodeId' => 's1', 'locationHint' => $location]),
            $this->write(MessageName::TEST_FAILED, ['name' => $name, 'nodeId' => $node, 'message' => "Mutator: IntegerNegation\nMutation ID: bbb\nMutation result: escaped", 'duration' => $ms, 'type' => 'comparisonFailure', 'actual' => 'return 1;', 'expected' => 'return -1;']),
        ];
    }
}
