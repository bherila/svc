<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Feature\Billing\BillingHistorySequenceHourlyMonthlyTest;
use Tests\Feature\Billing\BillingHistorySequenceHourlyQuarterlyTest;
use Tests\Feature\Billing\BillingHistorySequenceRetainerMonthlyTest;
use Tests\Feature\Billing\BillingHistorySequenceRolloverMonthlyTest;
use Tests\Feature\Billing\BillingHistorySequenceRolloverQuarterlyTest;

final class BillingHistorySequenceCoverageTest extends TestCase
{
    private const array WORLDS = [
        BillingHistorySequenceHourlyMonthlyTest::class => 'hourly, monthly',
        BillingHistorySequenceHourlyQuarterlyTest::class => 'hourly, quarterly',
        BillingHistorySequenceRetainerMonthlyTest::class => 'retainer, monthly',
        BillingHistorySequenceRolloverMonthlyTest::class => 'retainer with rollover, monthly',
        BillingHistorySequenceRolloverQuarterlyTest::class => 'retainer with rollover, quarterly',
    ];

    /** @return iterable<string, array{string|false, int}> */
    public static function seedCounts(): iterable
    {
        yield 'default' => [false, 4];
        yield 'CI' => ['16', 16];
        yield 'custom' => ['7', 7];
        yield 'zero uses the default' => ['0', 4];
        yield 'minimum' => ['-1', 1];
    }

    #[DataProvider('seedCounts')]
    public function test_every_world_and_seed_runs_once_in_an_independently_schedulable_file(string|false $configured, int $count): void
    {
        $before = getenv('BILLING_HISTORY_SEEDS');
        putenv($configured === false ? 'BILLING_HISTORY_SEEDS' : 'BILLING_HISTORY_SEEDS='.$configured);
        try {
            $actual = [];
            $expected = [];
            $files = [];
            $regressions = [];
            foreach (self::WORLDS as $class => $world) {
                $reflection = new ReflectionClass($class);
                $method = new ReflectionMethod($class, 'test_a_generated_history_keeps_its_invariants');
                // ParaTest schedules files, so inheriting the runner test from one shared file
                // would preserve the coverage but reintroduce the serial bottleneck.
                $this->assertSame($class, $method->getDeclaringClass()->getName());
                $this->assertSame($reflection->getFileName(), $method->getFileName());
                $files[] = $reflection->getFileName();
                foreach ($class::histories() as $name => $tuple) {
                    $this->assertArrayNotHasKey($name, $actual);
                    $actual[$name] = $tuple;
                }
                foreach (range(1, $count) as $seed) {
                    $expected["{$world}, seed {$seed}"] = [$world, $seed];
                }
                $tests = array_filter($reflection->getMethods(), fn (ReflectionMethod $test): bool => str_starts_with($test->name, 'test_'));
                $this->assertCount($class === BillingHistorySequenceRolloverMonthlyTest::class ? 2 : 1, $tests);
                foreach ($tests as $test) {
                    $this->assertSame($class, $test->getDeclaringClass()->getName(), 'Shared support must not contribute inherited tests.');
                    if ($test->name === 'test_late_work_after_an_empty_retainer_invoice_seed_6') {
                        $regressions[] = $class;
                    }
                }
            }
            $this->assertSame($expected, $actual);
            $discovered = glob(dirname(__DIR__).'/Feature/Billing/BillingHistorySequence*Test.php');
            sort($discovered);
            sort($files);
            $this->assertSame($files, $discovered, 'The suite must discover exactly these five concrete scheduling units.');
            $this->assertCount(5, array_unique($files));
            $this->assertSame([BillingHistorySequenceRolloverMonthlyTest::class], $regressions);
        } finally {
            putenv($before === false ? 'BILLING_HISTORY_SEEDS' : 'BILLING_HISTORY_SEEDS='.$before);
        }
    }
}
