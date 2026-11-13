<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use Tests\TestCase;

/**
 * What the diff-scoped mutation lane did not measure (#661): a changed line in
 * a method no Unit test runs, or in a file the Unit suite never loaded, is
 * named instead of passing as "0 mutants".
 */
#[CoversNothing]
final class MutationCoverageScriptTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once base_path('scripts/mutation-coverage.php');
    }

    public function test_changed_lines_are_read_from_a_zero_context_diff(): void
    {
        $diff = implode("\n", [
            'diff --git a/app/Services/SyntheticExample.php b/app/Services/SyntheticExample.php',
            '--- a/app/Services/SyntheticExample.php',
            '+++ b/app/Services/SyntheticExample.php',
            '@@ -10 +10,2 @@ final class SyntheticExample',
            '@@ -30,3 +32 @@',
            '@@ -40,2 +41,0 @@',
            '--- a/app/Removed.php',
            '+++ /dev/null',
            '@@ -1,4 +0,0 @@',
        ]);

        $this->assertSame(['app/Services/SyntheticExample.php' => [10, 11, 32]], mutation_coverage_changed_lines($diff));
    }

    public function test_a_change_no_unit_test_runs_is_named_and_a_measured_one_is_not(): void
    {
        $coverage = base_path('tests/Fixtures/mutation/coverage');

        $this->assertSame([], mutation_coverage_unmeasured(['app/Services/SyntheticExample.php' => [10, 22]], $coverage),
            'A covered method, and one with nothing to execute, are measured.');
        $this->assertSame(
            ['app/Services/SyntheticExample.php: App\Services\SyntheticExample::unmeasured', 'app/Services/NeverLoaded.php: not loaded by the Unit suite'],
            mutation_coverage_unmeasured([
                'app/Services/SyntheticExample.php' => [3, 17, 18],
                'app/Services/NeverLoaded.php' => [5],
                'tests/Unit/Ignored.php' => [1],
            ], $coverage),
        );
    }
}
