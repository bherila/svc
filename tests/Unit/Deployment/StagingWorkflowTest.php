<?php

namespace Tests\Unit\Deployment;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

final class StagingWorkflowTest extends TestCase
{
    private const FILE = '/.github/workflows/staging-deploy.yml';

    public function test_it_reads_only_staging_secrets_and_variables(): void
    {
        $text = (string) file_get_contents($this->root().self::FILE);

        preg_match_all('/\bsecrets\.([A-Za-z0-9_]+)/', $text, $secrets);
        preg_match_all('/\bvars\.([A-Za-z0-9_]+)/', $text, $vars);
        $this->assertNotEmpty($secrets[1]);
        $this->assertNotEmpty($vars[1]);
        foreach ([...$secrets[1], ...$vars[1]] as $name) {
            $this->assertStringStartsWith('STAGING_', $name, 'Staging must never read a production, pilot or shared setting.');
        }
        foreach (['SSH_HOST', 'ATOMIC_DEPLOY', 'PILOT_', 'svc.bherila.net', 'svc-laravel', 'secrets.GITHUB_TOKEN', 'web1'] as $production) {
            $this->assertStringNotContainsString($production, str_replace('STAGING_SSH_HOST', '', $text), $production);
        }
    }

    public function test_it_deploys_main_through_the_staging_environment_only_after_the_main_gate_and_preflight(): void
    {
        $job = $this->job();
        $this->assertSame('staging', $job['environment']);
        $this->assertSame("\${{ github.ref == 'refs/heads/main' }}", $job['if']);
        $this->assertArrayNotHasKey('needs', $job);

        $steps = $job['steps'];
        $names = array_map(static fn (array $step): string => $step['name'] ?? '', $steps);
        $gate = array_search('Require a main commit and its successful main CI', $names, true);
        $target = array_search('Check out the verified main commit', $names, true);
        $preflight = array_search('Preflight - list missing staging configuration', $names, true);
        $build = array_search('Build this exact source revision', $names, true);
        $deploy = array_search('Publish the isolated staging release', $names, true);
        foreach ([$gate, $target, $preflight, $build, $deploy] as $index) {
            $this->assertIsInt($index);
        }
        $this->assertTrue($gate < $target && $target < $preflight && $preflight < $build && $build < $deploy);

        // The gate runs from the dispatched main head, before any step that sees a secret.
        $this->assertSame('${{ github.sha }}', $steps[0]['with']['ref']);
        $this->assertStringNotContainsString('secrets.', Yaml::dump(array_slice($steps, 0, $preflight), 10));
        $this->assertSame('gate', $steps[$gate]['id']);
        $this->assertSame('node scripts/deploy/staging/gate.mjs', $steps[$gate]['run']);
        $this->assertSame('${{ inputs.sha }}', $steps[$gate]['env']['STAGING_REQUESTED_SHA']);
        $this->assertSame('${{ steps.gate.outputs.sha }}', $steps[$target]['with']['ref']);
        $this->assertSame('${{ steps.gate.outputs.sha }}', $steps[$build]['env']['STAGING_SHA']);
        $this->assertStringContainsString('test "$(git rev-parse HEAD)" = "$STAGING_SHA"', $steps[$build]['run']);
        foreach ($steps as $step) {
            $this->assertStringNotContainsString('inputs.', (string) ($step['with']['ref'] ?? ''), 'Only a verified SHA is checked out.');
        }

        $this->assertSame('node scripts/deploy/staging/preflight.mjs', $steps[$preflight]['run']);
        $this->assertStringNotContainsString('secrets.', Yaml::dump(array_slice($steps, $preflight + 1, $deploy - $preflight - 1), 10), 'Build steps never see secrets.');
    }

    public function test_it_is_a_manual_dispatch_only_and_enables_the_scheduler(): void
    {
        $workflow = Yaml::parseFile($this->root().self::FILE);
        $on = $workflow['on'] ?? $workflow[true];
        $this->assertSame(['workflow_dispatch'], array_keys($on), 'No push or pull request trigger deploys staging.');
        $this->assertSame(['sha'], array_keys($on['workflow_dispatch']['inputs']));
        $this->assertSame('string', $on['workflow_dispatch']['inputs']['sha']['type']);
        $this->assertFalse($on['workflow_dispatch']['inputs']['sha']['required']);
        $this->assertStringNotContainsString('inputs.ref', (string) file_get_contents($this->root().self::FILE));

        $deploy = $this->job()['steps'][array_search('Publish the isolated staging release', array_map(static fn (array $step): string => $step['name'] ?? '', $this->job()['steps']), true)];
        $this->assertSame('true', $deploy['with']['install-cron']);
        $this->assertStringContainsString('SVC_CSP_REPORT_ONLY=true', $deploy['with']['env-values']);
        $this->assertStringContainsString('STAGING_DEPLOYMENT=1', $deploy['with']['env-assert']);
        $this->assertStringContainsString('STRIPE_SECRET_KEY=${{ secrets.STAGING_STRIPE_SECRET_KEY }}', $deploy['with']['env-values']);
        $this->assertStringContainsString('install-cron', (string) file_get_contents($this->root().self::FILE));
    }

    public function test_it_is_not_a_need_of_ci_and_ci_is_not_triggered_by_it(): void
    {
        $tests = Yaml::parseFile($this->root().'/.github/workflows/tests.yml');
        foreach ($tests['jobs'] as $name => $job) {
            $this->assertStringNotContainsString('staging', strtolower(implode(',', (array) ($job['needs'] ?? []))), $name);
        }
        $this->assertNotContains('deploy-staging', $tests['jobs']['ci']['needs']);
        $this->assertStringNotContainsString('staging-deploy', (string) file_get_contents($this->root().'/.github/workflows/tests.yml'));
    }

    public function test_the_gate_accepts_the_dispatched_main_head_or_an_earlier_main_commit_with_green_main_ci(): void
    {
        $this->requireNode();
        $result = $this->gate();
        $this->assertTrue($result->isSuccessful(), $result->getErrorOutput());
        $this->assertSame(str_repeat('a', 40), $result->getOutput());

        $older = str_repeat('b', 40);
        $result = $this->gate(['env' => ['STAGING_REQUESTED_SHA' => $older], 'compare' => ['status' => 'ahead', 'ahead_by' => 3]]);
        $this->assertTrue($result->isSuccessful(), $result->getErrorOutput());
        $this->assertSame($older, $result->getOutput());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function refusedStagingRequests(): array
    {
        $other = str_repeat('c', 40);

        return [
            'push trigger' => [['env' => ['GITHUB_EVENT_NAME' => 'push']]],
            'pull request trigger' => [['env' => ['GITHUB_EVENT_NAME' => 'pull_request']]],
            'dispatch from a branch' => [['env' => ['GITHUB_REF' => 'refs/heads/feature']]],
            'dispatch from a pull request ref' => [['env' => ['GITHUB_REF' => 'refs/pull/7/merge']]],
            'branch name instead of a SHA' => [['env' => ['STAGING_REQUESTED_SHA' => 'feature']]],
            'pull request head ref' => [['env' => ['STAGING_REQUESTED_SHA' => 'refs/pull/7/head']]],
            'short SHA' => [['env' => ['STAGING_REQUESTED_SHA' => 'aaaaaaa']]],
            'uppercase SHA' => [['env' => ['STAGING_REQUESTED_SHA' => str_repeat('A', 40)]]],
            'commit not on main' => [['env' => ['STAGING_REQUESTED_SHA' => $other], 'compare' => ['status' => 'diverged', 'behind_by' => 2, 'merge_base_commit' => ['sha' => str_repeat('d', 40)]]]],
            'commit ahead of main' => [['env' => ['STAGING_REQUESTED_SHA' => $other], 'compare' => ['status' => 'behind', 'behind_by' => 1, 'ahead_by' => 0, 'merge_base_commit' => ['sha' => str_repeat('a', 40)]]]],
            'merge base is another commit' => [['compare' => ['merge_base_commit' => ['sha' => $other]]]],
            'CI ran for a pull request' => [['run' => ['event' => 'pull_request']]],
            'CI ran on a branch' => [['run' => ['head_branch' => 'feature']]],
            'CI ran in a fork' => [['run' => ['head_repository' => ['full_name' => 'another/fork']]]],
            'no CI run' => [['runCount' => 0]],
            'failed ci job' => [['jobs' => [['id' => 21, 'name' => 'ci', 'status' => 'completed', 'conclusion' => 'failure', 'head_sha' => str_repeat('a', 40)]]]],
            'missing ci job' => [['jobs' => [['id' => 21, 'name' => 'Run Tests', 'status' => 'completed', 'conclusion' => 'success', 'head_sha' => str_repeat('a', 40)]]]],
        ];
    }

    /** @param  array<string, mixed>  $fixture */
    #[DataProvider('refusedStagingRequests')]
    public function test_the_gate_refuses_anything_but_a_main_commit_with_green_main_ci(array $fixture): void
    {
        $this->requireNode();
        $result = $this->gate($fixture);
        $this->assertFalse($result->isSuccessful(), $result->getOutput());
        $this->assertSame('', $result->getOutput());
    }

    public function test_preflight_names_everything_missing_and_refuses_unsafe_values(): void
    {
        $node = (new ExecutableFinder)->find('node');
        if ($node === null) {
            $this->markTestSkipped('node is not available.');
        }

        [$code, $err] = $this->preflight($node, []);
        $this->assertSame(1, $code);
        foreach (['STAGING_DEPLOY_ENABLED', 'STAGING_SSH_HOST', 'STAGING_SSH_PRIVATE_KEY', 'STAGING_APP_URL', 'STAGING_DB_NAME', 'STAGING_DB_USER', 'STAGING_DB_PASSWORD', 'STAGING_APP_KEY', 'STAGING_STRIPE_SECRET_KEY', 'STAGING_STRIPE_WEBHOOK_SECRET', 'STAGING_MAIL_MAILER', 'STAGING_OAUTH_CLIENT_ID', 'STAGING_OAUTH_CLIENT_SECRET', 'STAGING_OAUTH_REDIRECT_URI'] as $name) {
            $this->assertStringContainsString($name.' (', $err);
        }

        $good = $this->complete();
        [$code, $err] = $this->preflight($node, $good);
        $this->assertSame(0, $code, $err);

        foreach ([
            'STAGING_STRIPE_SECRET_KEY' => 'sk_live_'.str_repeat('a', 24),
            'STAGING_APP_URL' => 'https://svc.bherila.net',
            'STAGING_DEPLOY_DIR' => 'svc-laravel',
            'STAGING_ENV_SOURCE' => '.config/svc/deployment.env',
            'STAGING_MAIL_MAILER' => 'brevo',
            'STAGING_DEPLOY_ENABLED' => 'false',
        ] as $name => $value) {
            [$code, $err] = $this->preflight($node, [$name => $value] + $good);
            $this->assertSame(1, $code, $name);
            $this->assertStringContainsString($name.' is invalid', $err);
            $this->assertStringNotContainsString($value, $err, 'Values are never printed.');
        }

        [$code, $err] = $this->preflight($node, ['STAGING_MAIL_MAILER' => 'smtp'] + $good);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('STAGING_MAIL_HOST (var) is missing', $err);
    }

    private function requireNode(): void
    {
        if ((new ExecutableFinder)->find('node') === null) {
            $this->markTestSkipped('node is not available.');
        }
    }

    /** @param  array<string, mixed>  $fixture */
    private function gate(array $fixture = []): Process
    {
        $source = <<<'JS'
import {pathToFileURL} from 'node:url';
const modulePath = process.argv[1]; process.argv[1] = 'fixture-runner';
const gate = await import(pathToFileURL(modulePath));
const fixture = JSON.parse(process.argv[2]);
const env = {GITHUB_REPOSITORY:'example/application', GITHUB_SHA:'a'.repeat(40), GITHUB_REF:'refs/heads/main', GITHUB_EVENT_NAME:'workflow_dispatch', STAGING_REQUESTED_SHA:'', ...fixture.env};
const sha = env.STAGING_REQUESTED_SHA || env.GITHUB_SHA;
const get = async (path) => {
    if (path.includes('/compare/')) return {status:'identical', ahead_by:0, behind_by:0, merge_base_commit:{sha}, ...fixture.compare};
    if (path.includes('/runs?')) {
        if (!path.includes('branch=main&event=push') || !path.includes('/workflows/tests.yml/')) throw new Error('unexpected CI query ' + path);
        const count = fixture.runCount ?? 1;
        return {total_count:count, workflow_runs:count ? [{id:42, head_sha:sha, head_branch:'main', event:'push', head_repository:{full_name:env.GITHUB_REPOSITORY}, ...fixture.run}] : []};
    }
    return {total_count:1, jobs:fixture.jobs ?? [{id:21, name:'ci', status:'completed', conclusion:'success', head_sha:sha}]};
};
try {process.stdout.write(await gate.verify(env, get));}
catch (error) {process.stderr.write(error.message); process.exitCode = 1;}
JS;
        $process = new Process(['node', '--input-type=module', '-e', $source,
            $this->root().'/scripts/deploy/staging/gate.mjs', json_encode((object) $fixture, JSON_THROW_ON_ERROR)]);
        $process->run();

        return $process;
    }

    /** @return array<string, mixed> */
    private function job(): array
    {
        return Yaml::parseFile($this->root().self::FILE)['jobs']['deploy'];
    }

    /** @return array<string, string> */
    private function complete(): array
    {
        return [
            'STAGING_DEPLOY_ENABLED' => 'true', 'STAGING_DEPLOY_DIR' => 'svc-staging',
            'STAGING_ENV_SOURCE' => '.config/svc-staging/deployment.env',
            'STAGING_APP_URL' => 'https://staging.example.test', 'STAGING_DB_HOST' => '127.0.0.1',
            'STAGING_DB_NAME' => 'svc_staging', 'STAGING_DB_USER' => 'svc_staging',
            'STAGING_MAIL_MAILER' => 'log', 'STAGING_STRIPE_PUBLISHABLE_KEY' => 'pk_test_abc',
            'STAGING_OAUTH_PROVIDER_URL' => 'https://id.example.test', 'STAGING_OAUTH_CLIENT_ID' => 'client',
            'STAGING_OAUTH_REDIRECT_URI' => 'https://staging.example.test/oauth/callback',
            'STAGING_SSH_HOST' => 'host.example.test', 'STAGING_SSH_USERNAME' => 'deploy',
            'STAGING_SSH_PRIVATE_KEY' => 'key', 'STAGING_SSH_KNOWN_HOSTS' => 'host ssh-ed25519 AAAA',
            'STAGING_APP_KEY' => 'base64:'.str_repeat('A', 43).'=', 'STAGING_DB_PASSWORD' => 'pw',
            'STAGING_STRIPE_SECRET_KEY' => 'sk_test_'.str_repeat('a', 24),
            'STAGING_STRIPE_WEBHOOK_SECRET' => 'whsec_abc', 'STAGING_OAUTH_CLIENT_SECRET' => 'secret',
        ];
    }

    /**
     * @param  array<string, string>  $env
     * @return array{int, string}
     */
    private function preflight(string $node, array $env): array
    {
        $process = new Process([$node, $this->root().'/scripts/deploy/staging/preflight.mjs'], null, $env + ['PATH' => (string) getenv('PATH')]);
        $process->run();

        return [$process->getExitCode() ?? -1, $process->getErrorOutput()];
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
