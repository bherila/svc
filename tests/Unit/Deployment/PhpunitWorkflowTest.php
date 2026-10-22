<?php

namespace Tests\Unit\Deployment;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

final class PhpunitWorkflowTest extends TestCase
{
    public function test_both_database_lanes_are_independent_four_shard_jobs(): void
    {
        $jobs = $this->jobs();
        foreach (['sqlite', 'mariadb-tests'] as $lane) {
            $this->assertArrayHasKey($lane, $jobs);
            $job = $jobs[$lane];
            $this->assertSame('ubuntu-24.04-arm', $job['runs-on']);
            $this->assertSame([], $job['needs'] ?? []);
            $this->assertFalse($job['strategy']['fail-fast']);
            $this->assertSame([1, 2, 3, 4], $job['strategy']['matrix']['shard']);
            $commands = implode("\n", array_column($job['steps'], 'run'));
            $this->assertStringContainsString('scripts/ci/phpunit-shard.php', $commands);
            $this->assertStringContainsString('"$SHARD" 4', $commands);
            $this->assertStringContainsString('--parallel --processes=4', $commands);
            $this->assertStringContainsString('--configuration=', $commands);
            $this->assertStringNotContainsString('composer setup', $commands);
        }
        $this->assertSame('mariadb:10.6', $jobs['mariadb-tests']['services']['mariadb']['image']);
        $this->assertSame('mariadb', $jobs['mariadb-tests']['env']['DB_EXPECT_DRIVER']);
        $this->assertSame('sqlite', $jobs['sqlite']['env']['DB_EXPECT_DRIVER']);
        $this->assertArrayNotHasKey('services', $jobs['sqlite']);
    }

    public function test_existing_required_check_names_gate_all_their_dependencies(): void
    {
        $jobs = $this->jobs();
        $this->assertSame(['checks', 'sqlite', 'mariadb', 'mcp-smoke'], $jobs['ci']['needs']);
        $this->assertSame('Tests on MariaDB', $jobs['mariadb']['name']);
        $this->assertSame(['mariadb-tests'], $jobs['mariadb']['needs']);
        foreach (['ci', 'mariadb'] as $gate) {
            $this->assertSame('${{ always() }}', $jobs[$gate]['if']);
            $this->assertSame('${{ toJSON(needs) }}', $jobs[$gate]['steps'][1]['env']['NEEDS_JSON']);
            $this->assertSame('bash scripts/ci/require-success.sh', $jobs[$gate]['steps'][1]['run']);
            $this->assertArrayNotHasKey('continue-on-error', $jobs[$gate]);
        }
        $this->assertContains('ci', $jobs['deploy']['needs']);
        $this->assertStringContainsString("needs.ci.result == 'success'", $jobs['deploy']['if']);
    }

    public function test_universal_gates_do_not_require_pull_request_only_jobs(): void
    {
        $jobs = $this->jobs();
        foreach (['ci', 'mariadb'] as $gate) {
            foreach ($jobs[$gate]['needs'] as $lane) {
                $this->assertStringNotContainsString('github.event_name', $jobs[$lane]['if'] ?? '', $gate.' cannot require a lane skipped on main or manual dispatch.');
            }
        }
    }

    public function test_checks_keep_static_and_frontend_validation_without_running_phpunit(): void
    {
        $jobs = $this->jobs();
        $this->assertSame([], $jobs['checks']['needs'] ?? []);
        $commands = implode("\n", array_column($jobs['checks']['steps'], 'run'));
        $this->assertStringContainsString('composer ci:checks', $commands);
        $this->assertStringNotContainsString('composer ci:check'."\n", $commands);
        $composer = json_decode(file_get_contents(dirname(__DIR__, 3).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $checks = $composer['scripts']['ci:checks'];
        foreach (['pnpm run lint:check', 'pnpm run format:check', 'pnpm run types:check', 'pnpm run api:types:check', 'pnpm run test', 'pnpm run scan-sensitive:test', 'pnpm run scan-sensitive', '@lint:check', '@types:check'] as $command) {
            $this->assertContains($command, $checks);
        }
        $this->assertNotContains('@test', $checks);
        $this->assertStringNotContainsString('artisan test', implode("\n", $checks));
    }

    public function test_gate_refuses_failed_cancelled_skipped_or_missing_dependencies(): void
    {
        foreach (['success' => 0, 'failure' => 1, 'cancelled' => 1, 'skipped' => 1, 'unknown' => 1] as $result => $exit) {
            $process = new Process(['bash', 'scripts/ci/require-success.sh'], dirname(__DIR__, 3), [
                'NEEDS_JSON' => json_encode(['first' => ['result' => 'success'], 'last' => ['result' => $result]], JSON_THROW_ON_ERROR),
            ]);
            $process->run();
            $this->assertSame($exit, $process->getExitCode(), $result.': '.$process->getErrorOutput());
        }
        foreach (['{}', '{"job":{}}', 'not json'] as $invalid) {
            $process = new Process(['bash', 'scripts/ci/require-success.sh'], dirname(__DIR__, 3), ['NEEDS_JSON' => $invalid]);
            $process->run();
            $this->assertNotSame(0, $process->getExitCode());
        }
    }

    private function jobs(): array
    {
        return Yaml::parseFile(dirname(__DIR__, 3).'/.github/workflows/tests.yml')['jobs'];
    }
}
