<?php

namespace Tests\Unit;

use Infection\Process\MutantProcess;
use Infection\Process\MutantProcessContainer;
use Infection\Process\Runner\ParallelProcessRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class MutationProcessPollingTest extends TestCase
{
    public function test_slow_generation_does_not_turn_an_exited_child_into_a_timeout(): void
    {
        [$first, $second] = $this->runWithSlowGeneration('exit(1);');

        $this->assertFalse($first->isTimedOut());
        $this->assertSame(1, $first->getProcess()->getExitCode());
        $this->assertFalse($second->isTimedOut());
    }

    public function test_a_child_still_running_beyond_its_deadline_remains_a_timeout(): void
    {
        [$first] = $this->runWithSlowGeneration('usleep(2000000);');

        $this->assertTrue($first->isTimedOut());
    }

    /** @return array{MutantProcess, MutantProcess} */
    private function runWithSlowGeneration(string $firstScript): array
    {
        $first = $this->child($firstScript);
        $second = $this->child('exit(1);');
        $containers = (static function () use ($first, $second): \Generator {
            yield new MutantProcessContainer($first, []);
            yield new MutantProcessContainer($second, []);
            // The runner starts the first child before asking for the next
            // mutation. Generation can outlive that child's short deadline.
            usleep(500000);
        })();

        iterator_to_array((new ParallelProcessRunner(1, poll: 1000))->run($containers));

        return [$first, $second];
    }

    private function child(string $script): MutantProcess
    {
        $process = new Process([PHP_BINARY, '-r', $script], timeout: 0.2);
        $mutant = $this->getMockBuilder(MutantProcess::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getProcess'])
            ->getMock();
        $mutant->expects($this->atLeastOnce())->method('getProcess')->willReturn($process);

        return $mutant;
    }
}
