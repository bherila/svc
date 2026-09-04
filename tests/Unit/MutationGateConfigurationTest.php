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
        $runner = file_get_contents(base_path('scripts/run-mutation-diff.sh'));

        $this->assertIsString($configuration);
        $this->assertIsString($runner);
        $this->assertMatchesRegularExpression('/directories:\s*\["app"\]/', $configuration);
        $this->assertStringContainsString('summaryJson:', $configuration);
        $this->assertStringContainsString('--testsuite=Unit', $configuration);
        $this->assertStringContainsString('timeout: 30', $configuration);
        $this->assertStringNotContainsString('ExternalImport', $configuration);
        $this->assertStringContainsString('scripts/run-mutation-diff.sh', $command);
        $this->assertStringContainsString('MUTATION_BASE:-origin/main', $runner);
        $this->assertStringContainsString('GIT_CONFIG_KEY_0=diff.renames', $runner);
        $this->assertStringContainsString('GIT_CONFIG_VALUE_0=false', $runner);
        $this->assertStringContainsString('--git-diff-lines', $runner);
        $this->assertStringContainsString('--min-covered-msi=82', $runner);
        $this->assertStringNotContainsString('--with-uncovered', $runner);
        $this->assertStringNotContainsString('--min-msi=', $runner);
    }

    public function test_actions_match_the_application_scope_and_name_both_empty_results(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/tests.yml'));

        $this->assertIsString($workflow);
        $this->assertStringContainsString("':(glob)app/**/*.php'", $workflow);
        $this->assertStringContainsString('merge_base=$(git merge-base "$BASE_SHA" "$HEAD_SHA")', $workflow);
        $this->assertStringContainsString('git -c diff.renames=false diff', $workflow);
        $this->assertStringContainsString('MUTATION_BASE: ${{ steps.mutation_scope.outputs.merge_base }}', $workflow);
        $this->assertStringContainsString('.stats.coveredCodeMsi // .coveredCodeMutationScoreIndicator', $workflow);
        $this->assertStringContainsString('Mutation gate not applicable', $workflow);
        $this->assertStringContainsString('Mutation gate produced zero mutants', $workflow);
        $this->assertStringContainsString('No mutants were produced', $workflow);
        $this->assertStringContainsString('continue-on-error: true', $workflow);
    }

    public function test_the_escape_policy_requires_a_code_local_reason(): void
    {
        $documentation = file_get_contents(base_path('docs/client-management/mutation-testing.md'));

        $this->assertIsString($documentation);
        $this->assertStringContainsString('`@infection-ignore-all`', $documentation);
        $this->assertStringContainsString('adjacent', $documentation);
        $this->assertStringContainsString('Do not add a new', $documentation);
        $this->assertStringContainsString('configuration-only exemption', $documentation);
    }
}
