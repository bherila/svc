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
}
