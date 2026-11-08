<?php

namespace Tests\Unit\Deployment;

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

    public function test_it_deploys_through_the_staging_environment_only_after_preflight_and_the_ci_gate(): void
    {
        $job = $this->job();
        $this->assertSame('staging', $job['environment']);
        $this->assertArrayNotHasKey('needs', $job);

        $steps = $job['steps'];
        $names = array_map(static fn (array $step): string => $step['name'] ?? '', $steps);
        $preflight = array_search('Preflight - list missing staging configuration', $names, true);
        $gate = array_search("Require this exact commit's successful CI", $names, true);
        $deploy = array_search('Publish the isolated staging release', $names, true);
        $this->assertIsInt($preflight);
        $this->assertIsInt($gate);
        $this->assertIsInt($deploy);
        $this->assertTrue($preflight < $gate && $gate < $deploy);
        $this->assertSame('node scripts/deploy/staging/preflight.mjs', $steps[$preflight]['run']);
        $this->assertStringContainsString('gate.mjs', $steps[$gate]['run']);
        $this->assertStringNotContainsString('secrets.', Yaml::dump(array_slice($steps, $gate, $deploy - $gate), 10), 'Build steps never see secrets.');
    }

    public function test_it_is_manual_or_a_staging_branch_push_and_enables_the_scheduler(): void
    {
        $workflow = Yaml::parseFile($this->root().self::FILE);
        $on = $workflow['on'] ?? $workflow[true];
        $this->assertSame('string', $on['workflow_dispatch']['inputs']['ref']['type']);
        $this->assertTrue($on['workflow_dispatch']['inputs']['ref']['required']);
        $this->assertSame(['staging'], $on['push']['branches']);
        $this->assertSame(['workflow_dispatch', 'push'], array_keys($on));

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
