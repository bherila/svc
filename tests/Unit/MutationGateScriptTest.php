<?php

namespace Tests\Unit;

use Tests\TestCase;

final class MutationGateScriptTest extends TestCase
{
    private function run_gate(string $fixture): array
    {
        $path = base_path('tests/Fixtures/mutation/'.$fixture);
        $out = [];
        $status = 0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('scripts/mutation-gate.php')).' '.escapeshellarg($path).' 2>&1', $out, $status);

        return [$status, implode("\n", $out)];
    }

    public function test_a_run_where_every_mutant_timed_out_or_was_skipped_fails(): void
    {
        [$status, $output] = $this->run_gate('all-timed-out.json');

        $this->assertSame(1, $status);
        $this->assertStringContainsString('14 total, 0 killed, 0 escaped, 0 timed out, 14 skipped', $output);
        $this->assertStringContainsString('14 of 14 mutants timed out or were skipped', $output);
    }

    public function test_a_normal_run_and_an_empty_run_pass(): void
    {
        [$status, $output] = $this->run_gate('normal.json');
        $this->assertSame(0, $status);
        $this->assertStringContainsString('14 total, 13 killed', $output);

        [$status] = $this->run_gate('zero.json');
        $this->assertSame(0, $status);
    }

    public function test_a_missing_summary_fails_and_the_threshold_is_strictly_more_than_half(): void
    {
        [$status] = $this->run_gate('does-not-exist.json');
        $this->assertSame(1, $status);

        require_once base_path('scripts/mutation-gate.php');
        $this->assertTrue(mutation_gate_evaluate(['totalMutantsCount' => 4, 'timeOutCount' => 2])['ok']);
        $this->assertFalse(mutation_gate_evaluate(['totalMutantsCount' => 4, 'timeOutCount' => 2, 'skippedCount' => 1])['ok']);
        $this->assertFalse(mutation_gate_evaluate(['totalMutantsCount' => 1, 'timeOutCount' => 1])['ok']);
    }
}
