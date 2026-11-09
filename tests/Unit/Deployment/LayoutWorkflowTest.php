<?php

namespace Tests\Unit\Deployment;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class LayoutWorkflowTest extends TestCase
{
    public function test_every_requested_layout_has_an_independent_job_command_and_artifact(): void
    {
        $workflow = Yaml::parseFile(dirname(__DIR__, 3).'/.github/workflows/tests.yml');
        $selected = [];
        foreach (['portal' => ['--portal-access', 'portal-access-layout'], 'correction' => ['--invoice-correction', 'invoice-correction-layout'], 'square' => ['--square', 'square-layout'], 'reminder' => ['--reminders', 'reminder-layout']] as $lane => [$mode, $artifact]) {
            $matches = [];
            foreach ($workflow['jobs'] as $id => $job) {
                if (! str_contains($job['if'] ?? '', 'inputs.'.$lane.'_layout')) {
                    continue;
                }
                $matches[$id] = $job;
            }
            $this->assertCount(1, $matches, $lane.' must select exactly one job.');
            $selected[] = array_key_first($matches);
            $job = reset($matches);
            $this->assertSame([], $job['needs'] ?? [], 'Independent layout jobs must be concurrent.');
            foreach (array_diff(['portal', 'correction', 'square', 'reminder'], [$lane]) as $other) {
                $this->assertStringNotContainsString('inputs.'.$other.'_layout', $job['if']);
            }
            $checks = array_values(array_filter($job['steps'], static fn (array $step): bool => str_contains($step['run'] ?? '', 'scripts/check-layout.mjs')));
            $this->assertCount(1, $checks);
            $this->assertSame('node scripts/check-layout.mjs '.$mode, $checks[0]['run']);
            $uploads = array_values(array_filter($job['steps'], static fn (array $step): bool => str_starts_with($step['uses'] ?? '', 'actions/upload-artifact@')));
            $this->assertCount(1, $uploads);
            $this->assertSame($artifact, $uploads[0]['with']['name']);
        }
        $this->assertCount(4, array_unique($selected));
    }

    public function test_every_screen_group_of_the_harness_runs_on_every_pull_request(): void
    {
        $root = dirname(__DIR__, 3);
        $workflow = Yaml::parseFile($root.'/.github/workflows/tests.yml');
        $harness = file_get_contents($root.'/scripts/check-layout.mjs');
        preg_match_all("/process\\.argv\\.includes\\('(--[a-z-]+)'\\)/", $harness, $found);
        // --skip-build is not a group; every other flag selects one.
        $groups = array_values(array_diff(array_unique($found[1]), ['--skip-build']));
        sort($groups);
        $this->assertNotEmpty($groups);

        $job = $workflow['jobs']['layout'];
        $this->assertArrayNotHasKey('if', $job, 'Layout groups gate every push and pull request, not only a dispatch.');
        $this->assertRunsOnlyWhenAScreenCanChange($workflow['jobs']['layout'], 'layout');
        $this->assertRunsOnlyWhenAScreenCanChange($workflow['jobs']['layout-scripts'], 'layout-scripts');
        $this->assertSame([], $job['needs'] ?? [], 'Layout shards must be concurrent.');
        $this->assertFalse($job['strategy']['fail-fast']);

        $scheduled = [];
        foreach ($job['strategy']['matrix']['include'] as $shard) {
            preg_match_all('/--[a-z-]+/', $shard['groups'], $flags);
            array_push($scheduled, ...$flags[0]);
        }
        $this->assertEmpty(array_diff($groups, $scheduled), 'Every harness group needs a shard: '.implode(' ', array_diff($groups, $scheduled)));
        $this->assertSame(array_values(array_unique($scheduled)), $scheduled, 'A group runs in one shard only.');
        $this->assertContains('', array_column($job['strategy']['matrix']['include'], 'groups'), 'The default screens need a shard.');

        $this->assertSame(['layout', 'layout-scripts'], $workflow['jobs']['layout-gate']['needs']);
        foreach (glob($root.'/scripts/check-*-layout.mjs') as $script) {
            $name = 'scripts/'.basename($script);
            if ($name === 'scripts/check-layout.mjs') {
                continue;
            }
            $scripts = array_column($workflow['jobs']['layout-scripts']['strategy']['matrix']['include'], 'script');
            if (str_contains(file_get_contents($script), 'accessibilityFindings')) {
                $this->assertContains($name, $scripts, $name.' gates accessibility, so CI must run it.');
            }
        }
        $this->assertContains('layout-gate', $workflow['jobs']['ci']['needs']);
    }

    /**
     * A shard decides first, from scripts/layout-scope.sh, whether the change
     * can affect a rendered screen, and every later step waits on that: a
     * skipped shard still reports success to the layout gate, while a push to
     * main or a dispatch always runs.
     *
     * @param  array<string, mixed>  $job
     */
    private function assertRunsOnlyWhenAScreenCanChange(array $job, string $name): void
    {
        $steps = $job['steps'];
        $this->assertSame('Checkout code', $steps[0]['name'], $name);
        $this->assertSame(0, $steps[0]['with']['fetch-depth'] ?? null, $name.' needs the base commit to compare against.');
        $this->assertSame('layout_scope', $steps[1]['id'] ?? null, $name.' decides its scope straight after checkout.');
        $this->assertStringContainsString('source scripts/layout-scope.sh', $steps[1]['run']);
        $this->assertStringContainsString('"$EVENT_NAME" != pull_request', $steps[1]['run'], 'Only a pull request may skip the screens.');
        foreach (array_slice($steps, 2) as $step) {
            $this->assertStringContainsString("steps.layout_scope.outputs.required == 'true'", (string) ($step['if'] ?? ''), $name.': '.$step['name'].' must wait on the layout scope.');
        }
    }
}
