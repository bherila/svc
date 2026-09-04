<?php

namespace Tests\Unit;

use Tests\TestCase;

final class MutationGateConfigurationTest extends TestCase
{
    public function test_the_diff_gate_has_one_broad_source_and_an_honest_covered_code_score(): void
    {
        $configuration = file_get_contents(base_path('infection.diff.json5'));
        $composer = json_decode(
            (string) file_get_contents(base_path('composer.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $command = implode(' ', $composer['scripts']['test:mutation-diff']);

        $this->assertIsString($configuration);
        $this->assertMatchesRegularExpression('/directories:\s*\["app"\]/', $configuration);
        $this->assertStringContainsString('summaryJson:', $configuration);
        $this->assertStringContainsString('--testsuite=Unit', $configuration);
        $this->assertStringContainsString('timeout: 30', $configuration);
        $this->assertStringNotContainsString('ExternalImport', $configuration);
        $this->assertStringContainsString('--min-covered-msi=82', $command);
        $this->assertStringNotContainsString('--with-uncovered', $command);
        $this->assertStringNotContainsString('--min-msi=', $command);
    }

    public function test_actions_match_the_application_scope_and_name_both_empty_results(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/tests.yml'));

        $this->assertIsString($workflow);
        $this->assertStringContainsString("':(glob)app/**/*.php'", $workflow);
        $this->assertStringContainsString('Mutation gate not applicable', $workflow);
        $this->assertStringContainsString('Mutation gate produced zero mutants', $workflow);
        $this->assertStringContainsString('No mutants were produced', $workflow);
        $this->assertStringContainsString('continue-on-error: true', $workflow);
    }
}
