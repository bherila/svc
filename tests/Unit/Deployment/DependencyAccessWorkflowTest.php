<?php

namespace Tests\Unit\Deployment;

use PHPUnit\Framework\TestCase;
use Svc\Ci\DependencyAudit;
use Symfony\Component\Yaml\Yaml;

final class DependencyAccessWorkflowTest extends TestCase
{
    public function test_no_workflow_authorises_a_private_dependency_over_ssh(): void
    {
        foreach (glob($this->root().'/.github/workflows/*.yml') ?: [] as $path) {
            $text = (string) file_get_contents($path);
            $this->assertStringNotContainsString('MCP_BRIDGE_DEPLOY_KEY', $text, basename($path));
            $this->assertStringNotContainsString('webfactory/ssh-agent', $text, basename($path));
        }
    }

    public function test_the_bridge_dependency_is_locked_over_https(): void
    {
        $lock = json_decode((string) file_get_contents($this->root().'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
        $bridge = array_values(array_filter($lock['packages'], static fn (array $package): bool => $package['name'] === 'bherila/mcp-laravel-bridge'));
        $this->assertCount(1, $bridge);
        $this->assertStringStartsWith('https://github.com/', $bridge[0]['source']['url']);
        $this->assertStringNotContainsString('git@', (string) file_get_contents($this->root().'/composer.json'));
    }

    public function test_clean_install_job_uses_no_secrets_and_gates_ci(): void
    {
        $workflow = Yaml::parseFile($this->root().'/.github/workflows/tests.yml');
        $job = $workflow['jobs']['clean-install'];
        $this->assertStringNotContainsString('secrets.', Yaml::dump($job, 10));
        $commands = implode("\n", array_column($job['steps'], 'run'));
        $this->assertStringContainsString('composer install', $commands);
        $this->assertStringContainsString('pnpm install --frozen-lockfile', $commands);
        $this->assertStringContainsString('php artisan about', $commands);
        $this->assertContains('clean-install', $workflow['jobs']['ci']['needs']);
    }

    public function test_dependency_audit_enforces_and_gates_ci(): void
    {
        $workflow = Yaml::parseFile($this->root().'/.github/workflows/tests.yml');
        $job = $workflow['jobs']['dependency-audit'];
        $commands = implode("\n", array_column($job['steps'], 'run'));

        // One script runs both audits and decides; the workflow no longer swallows a failure into a warning.
        $this->assertStringContainsString('php scripts/ci/dependency-audit.php', $commands);
        $this->assertStringNotContainsString('::warning', $commands);
        $this->assertStringNotContainsString('|| true', $commands);
        $this->assertArrayNotHasKey('continue-on-error', $job);
        foreach ($job['steps'] as $step) {
            $this->assertArrayNotHasKey('continue-on-error', $step);
        }
        $this->assertStringNotContainsString('(advisory)', $job['name']);
        $this->assertStringNotContainsString('secrets.', Yaml::dump($job, 10));
        $this->assertContains('dependency-audit', $workflow['jobs']['ci']['needs']);
        // The lane needs nothing but the lock files, so a slow install cannot delay or hide it.
        $this->assertArrayNotHasKey('needs', $job);
    }

    public function test_the_script_runs_the_two_audits_it_decides_on_and_the_exceptions_file_is_valid(): void
    {
        $script = (string) file_get_contents($this->root().'/scripts/ci/dependency-audit.php');
        $this->assertStringContainsString('composer audit --locked --format=json', $script);
        $this->assertStringContainsString('pnpm audit --prod --json', $script);

        require_once $this->root().'/scripts/ci/DependencyAudit.php';
        $exceptions = DependencyAudit::parseExceptions((string) file_get_contents($this->root().'/.github/audit-exceptions.json'));
        $this->assertTrue(array_is_list($exceptions));
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
