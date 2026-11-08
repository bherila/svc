<?php

namespace Tests\Unit\Deployment;

use PHPUnit\Framework\TestCase;
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

    public function test_dependency_audit_is_advisory(): void
    {
        $workflow = Yaml::parseFile($this->root().'/.github/workflows/tests.yml');
        $commands = implode("\n", array_column($workflow['jobs']['dependency-audit']['steps'], 'run'));
        $this->assertStringContainsString('composer audit --locked', $commands);
        $this->assertStringContainsString('pnpm audit --prod', $commands);
        $this->assertSame(2, substr_count($commands, '::warning title='), 'Each audit must surface its advisories as a warning.');
        foreach (['ci', 'mariadb', 'deploy'] as $gate) {
            $this->assertNotContains('dependency-audit', (array) $workflow['jobs'][$gate]['needs']);
        }
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
