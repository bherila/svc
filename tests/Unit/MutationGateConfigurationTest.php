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
        $this->assertStringContainsString('timeout: 120', $configuration);
        $this->assertStringNotContainsString('ExternalImport', $configuration);
        $this->assertStringContainsString('scripts/run-mutation-diff.sh', $command);
        $this->assertStringContainsString('MUTATION_BASE:-origin/main', $runner);
        $this->assertStringContainsString('GIT_CONFIG_KEY_0=diff.renames', $runner);
        $this->assertStringContainsString('GIT_CONFIG_VALUE_0=false', $runner);
        $this->assertStringContainsString('--git-diff-lines', $runner);
        $this->assertStringContainsString('--min-covered-msi=82', $runner);
        $this->assertStringContainsString('php scripts/mutation-gate.php', $runner);
        $this->assertStringNotContainsString('--with-uncovered', $runner);
        $this->assertStringNotContainsString('--min-msi=', $runner);
        // An agent shell swaps PHPUnit's output for a JSON line Infection
        // cannot read, which counts every mutant as killed.
        $this->assertMatchesRegularExpression('/^export PAO_DISABLE=1$/m', $runner);
        $this->assertLessThan(strpos($runner, 'php -d pcov'), strpos($runner, 'export PAO_DISABLE=1'));
        $this->assertContains('@putenv PAO_DISABLE=1', $composer['scripts']['test:mutation']);
    }

    public function test_the_runner_keeps_its_evidence_where_infection_does_not_delete_it(): void
    {
        $runner = (string) file_get_contents(base_path('scripts/run-mutation-diff.sh'));
        $workflow = (string) file_get_contents(base_path('.github/workflows/tests.yml'));

        // Infection appends `infection/` to its tmpDir and deletes that directory
        // after a run, so the baseline it reads and the per-mutant log live
        // beside it, and the workflow uploads those paths.
        $this->assertStringContainsString('diagnostics=storage/framework/cache/infection-diff/diagnostics', $runner);
        $this->assertStringNotContainsString('infection-diff/infection', $runner);
        $this->assertStringContainsString('storage/framework/cache/infection-diff/diagnostics/**', $workflow);
        $this->assertStringNotContainsString('infection-diff/coverage-xml', $workflow);
        $this->assertStringContainsString('php scripts/mutation-timings.php', $workflow);

        // The baseline runs first and must pass: under --skip-initial-tests an
        // ordinary failing test would otherwise read as a killed mutant.
        $baseline = strpos($runner, 'vendor/bin/phpunit');
        $mutants = strpos($runner, 'vendor/bin/infection');
        $this->assertIsInt($baseline);
        $this->assertIsInt($mutants);
        $this->assertLessThan($mutants, $baseline);
        $this->assertStringContainsString('exit "$baseline_status"', substr($runner, $baseline, $mutants - $baseline));
        foreach (['--testsuite=Unit', '--order-by=default', '--coverage-xml="$diagnostics/baseline/coverage-xml"', '--log-junit="$diagnostics/baseline/junit.xml"'] as $flag) {
            $this->assertStringContainsString($flag, substr($runner, $baseline, $mutants - $baseline));
        }
        foreach (['--coverage="$diagnostics/baseline"', '--skip-initial-tests', '--only-covering-test-cases', '--teamcity'] as $flag) {
            $this->assertStringContainsString($flag, substr($runner, $mutants));
        }
    }

    public function test_actions_match_the_application_scope_and_name_both_empty_results(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/tests.yml'));

        $this->assertIsString($workflow);
        $detector = (string) file_get_contents(base_path('scripts/mutation-scope.sh'));
        $runner = (string) file_get_contents(base_path('scripts/run-mutation-diff.sh'));
        $this->assertStringContainsString("':(glob)app/**/*.php'", $detector);
        $this->assertStringContainsString('ref: ${{ github.event.pull_request.head.sha || github.sha }}', $workflow);
        // A dispatched run measures an explicit base, e.g. the original base of
        // a merged pull request whose follow-up changes no application lines.
        $this->assertStringContainsString('BASE_SHA: ${{ github.event.pull_request.base.sha || inputs.mutation_base }}', $workflow);
        $this->assertStringContainsString("(github.event_name == 'workflow_dispatch' && inputs.mutation_base != '')", $workflow);
        $this->assertStringContainsString('source scripts/mutation-scope.sh', $workflow);
        $this->assertStringContainsString('mutation_scope "$BASE_SHA" "$HEAD_SHA"', $workflow);
        $this->assertStringContainsString('mutation-scope.sh', $runner);
        $this->assertStringContainsString('mutation_scope "$mutation_base" HEAD', $runner);
        $this->assertStringContainsString('git -c diff.renames=false diff', $detector);
        $this->assertSame(1, preg_match('/      - name: Detect mutation-tested changes\n(.*?)      - name: Setup PHP with coverage/s', $workflow, $detection));
        $this->assertStringNotContainsString('git diff', $detection[1]);
        $this->assertStringContainsString('timeout-minutes: 45', $workflow);
        $this->assertStringContainsString('MUTATION_BASE: ${{ steps.mutation_scope.outputs.merge_base }}', $workflow);
        $this->assertStringContainsString('.stats.coveredCodeMsi // .coveredCodeMutationScoreIndicator', $workflow);
        $this->assertStringContainsString('Mutation gate not applicable', $workflow);
        $this->assertStringContainsString('Mutation gate produced zero mutants', $workflow);
        $this->assertStringContainsString('No mutants were produced', $workflow);
        // The score stays advisory per step; the job itself must be able to fail
        // so a run that measured nothing cannot show a green lane (#443).
        $this->assertStringContainsString('        continue-on-error: true', $workflow);
        $this->assertStringContainsString('php scripts/mutation-gate.php', $workflow);
        $this->assertDoesNotMatchRegularExpression('/^  mutation:\n(?:(?!^  \S).*\n)*?    continue-on-error: true/m', $workflow);
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
