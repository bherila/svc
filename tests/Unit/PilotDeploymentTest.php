<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

class PilotDeploymentTest extends TestCase
{
    private const PILOT_ENV = "PILOT_DEPLOYMENT=1\nAPP_DEBUG=false\nMAIL_MAILER=log\nDB_CONNECTION=mariadb\nDB_HOST=127.0.0.1\n";

    private string $root;

    private string $temporary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
        $this->temporary = sys_get_temp_dir().'/pilot-deploy-'.bin2hex(random_bytes(8));
        mkdir($this->temporary, 0700);
        $this->temporary = realpath($this->temporary);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', '--', $this->temporary]))->mustRun();
        parent::tearDown();
    }

    /** @param array<string, mixed> $fixture */
    private function gate(array $fixture = [], bool $identity = false): Process
    {
        $source = <<<'JS'
import {pathToFileURL} from 'node:url';
const modulePath = process.argv[1]; process.argv[1] = 'fixture-runner';
const gate = await import(pathToFileURL(modulePath));
const fixture = JSON.parse(process.argv[2]);
const env = {GITHUB_REPOSITORY:'example/application', GITHUB_SHA:'a'.repeat(40), GITHUB_REF:'refs/heads/main', GITHUB_EVENT_NAME:'workflow_dispatch', PILOT_DEPLOY:'true', PILOT_OPERATOR_SHA:'a'.repeat(40), PILOT_IDENTITY_REPOSITORY:'example/identity', PILOT_APPLICATION_SHA:'b'.repeat(40), ...fixture.env};
const identity = process.argv[3] === 'identity';
const sha = identity ? env.PILOT_APPLICATION_SHA : env.PILOT_OPERATOR_SHA;
const repository = identity ? env.PILOT_IDENTITY_REPOSITORY : env.GITHUB_REPOSITORY;
const get = async (path) => {
    if (path.endsWith('/git/ref/heads/main')) return {object:{sha: fixture.main ?? env.PILOT_OPERATOR_SHA}};
    if (path.includes('/runs?')) return {total_count:fixture.runCount ?? 1, workflow_runs:[{id:42, head_sha:sha, head_branch:'main', event:'push', status:'completed', conclusion:'failure', head_repository:{full_name:repository}, ...fixture.run}]};
    return {total_count:fixture.jobCount ?? 1, jobs:fixture.jobs ?? [{id:21, name:identity ? 'ci' : (env.PILOT_CI_GATE ?? 'Run Tests'), status:'completed', conclusion:'success', head_sha:sha}]};
};
try {await (identity ? gate.verifyIdentity : gate.verifyOperator)(env, get); process.stdout.write('verified');}
catch (error) {process.stderr.write(error.message); process.exitCode = 1;}
JS;
        $process = new Process(['node', '--input-type=module', '-e', $source,
            $this->root.'/scripts/deploy/pilot/gate.mjs', json_encode($fixture, JSON_THROW_ON_ERROR),
            $identity ? 'identity' : 'operator']);
        $process->run();

        return $process;
    }

    public function test_exact_main_aggregate_success_does_not_depend_on_a_production_deployment(): void
    {
        $result = $this->gate();
        $this->assertTrue($result->isSuccessful(), $result->getErrorOutput());
        $this->assertTrue($this->gate([], true)->isSuccessful());
        $this->assertTrue($this->gate(['run' => ['status' => 'in_progress']])->isSuccessful());
        $this->assertTrue($this->gate(['run' => ['status' => 'waiting']])->isSuccessful());
        $this->assertTrue($this->gate(['env' => ['PILOT_CI_WORKFLOW' => 'tests.yml', 'PILOT_CI_GATE' => 'ci']])->isSuccessful());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function rejectedRequests(): array
    {
        return [
            'unconfirmed' => [['env' => ['PILOT_DEPLOY' => 'false']]],
            'pull request trigger' => [['env' => ['GITHUB_EVENT_NAME' => 'pull_request']]],
            'branch dispatch' => [['env' => ['GITHUB_REF' => 'refs/heads/feature']]],
            'wrong dispatch SHA' => [['env' => ['GITHUB_SHA' => str_repeat('c', 40)]]],
            'short SHA' => [['env' => ['PILOT_OPERATOR_SHA' => 'aaaaaaa']]],
            'main moved' => [['main' => str_repeat('c', 40)]],
            'wrong source event' => [['run' => ['event' => 'pull_request']]],
            'wrong source branch' => [['run' => ['head_branch' => 'feature']]],
            'fork source' => [['run' => ['head_repository' => ['full_name' => 'another/fork']]]],
            'wrong source SHA' => [['run' => ['head_sha' => str_repeat('c', 40)]]],
            'unbounded runs' => [['runCount' => 101]],
            'incomplete runs' => [['runCount' => 2]],
            'unbounded jobs' => [['jobCount' => 501]],
            'incomplete jobs' => [['jobCount' => 2]],
            'missing aggregate' => [['jobs' => [['id' => 21, 'name' => 'Deploy to Production', 'status' => 'completed', 'conclusion' => 'success', 'head_sha' => str_repeat('a', 40)]]]],
            'failed aggregate' => [['jobs' => [['id' => 21, 'name' => 'Run Tests', 'status' => 'completed', 'conclusion' => 'failure', 'head_sha' => str_repeat('a', 40)]]]],
            'wrong aggregate SHA' => [['jobs' => [['id' => 21, 'name' => 'Run Tests', 'status' => 'completed', 'conclusion' => 'success', 'head_sha' => str_repeat('b', 40)]]]],
        ];
    }

    #[DataProvider('rejectedRequests')]
    public function test_untrusted_or_unverified_requests_fail_before_deployment(array $fixture): void
    {
        $this->assertFalse($this->gate($fixture)->isSuccessful());
    }

    private function scaffold(): string
    {
        $home = $this->temporary.'/home';
        foreach (['.config/sample', '.deployments/sample-app/shared/storage', 'sample-app/public', 'sample-app/bootstrap/cache'] as $path) {
            mkdir($home.'/'.$path, 0700, true);
        }
        file_put_contents($home.'/.config/sample/deployment.env', self::PILOT_ENV);
        file_put_contents($home.'/sample-app/.env', self::PILOT_ENV);
        symlink($home.'/.deployments/sample-app/shared/storage', $home.'/sample-app/storage');

        return $home;
    }

    private function prepare(string $home): Process
    {
        $process = new Process(['bash', $this->root.'/scripts/deploy/pilot/prepare-host.sh',
            'sample-app', '.config/sample/deployment.env', PHP_BINARY], null, ['HOME' => $home]);
        $process->run();

        return $process;
    }

    public function test_first_install_preserves_only_the_known_scaffold_without_deleting_runtime_data(): void
    {
        $home = $this->scaffold();
        file_put_contents($home.'/.deployments/sample-app/shared/storage/preserved.txt', 'retained');
        $result = $this->prepare($home);
        $this->assertTrue($result->isSuccessful(), $result->getErrorOutput());
        $this->assertDirectoryDoesNotExist($home.'/sample-app');
        $this->assertFileExists($home.'/.deployments/sample-app/prepared-root/.env');
        $this->assertSame('retained', file_get_contents($home.'/.deployments/sample-app/shared/storage/preserved.txt'));
    }

    public function test_first_install_refuses_unknown_files_and_keeps_the_entire_root_in_place(): void
    {
        $home = $this->scaffold();
        file_put_contents($home.'/sample-app/public/unexpected.txt', 'preserve me');
        $this->assertFalse($this->prepare($home)->isSuccessful());
        $this->assertSame('preserve me', file_get_contents($home.'/sample-app/public/unexpected.txt'));
        $this->assertDirectoryDoesNotExist($home.'/.deployments/sample-app/prepared-root');
    }

    /** @return list<string> */
    private static function databaseConnections(): array
    {
        preg_match_all("/^        '([a-z]+)' => \[\R            'driver' => /m", (string) file_get_contents(dirname(__DIR__, 2).'/config/database.php'), $matches);

        return $matches[1];
    }

    /**
     * Every way config/database.php lets the environment move the default connection off the
     * local server: the connection itself, then each destination key the pilot's connection reads.
     *
     * @return array<string, array{array<string, string>}>
     */
    public static function databaseDestinationOverrides(): array
    {
        $overrides = [
            'remote URL overrides localhost' => [['DB_URL' => 'mysql://synthetic:synthetic@database.example.test/app']],
            'local URL overrides isolated credentials' => [['DB_URL' => 'mysql://synthetic:synthetic@127.0.0.1/another_app']],
            'socket replaces the host' => [['DB_SOCKET' => '/synthetic/another-server.sock']],
            'remote host' => [['DB_HOST' => 'database.example.test']],
            'forwarded local port' => [['DB_PORT' => '3307']],
            'missing connection falls back to the configured default' => [['DB_CONNECTION' => '']],
        ];
        foreach (array_diff(self::databaseConnections(), ['mariadb']) as $connection) {
            $overrides["{$connection} connection"] = [['DB_CONNECTION' => $connection, 'DB_DATABASE' => '/synthetic/another.sqlite']];
        }

        return $overrides;
    }

    /** @param array<string, string> $values */
    private static function pilotEnv(array $values = []): string
    {
        $env = self::PILOT_ENV;
        foreach ($values as $key => $value) {
            $line = $key.'='.$value."\n";
            $env = preg_match("/^{$key}=.*\$/m", $env) ? preg_replace("/^{$key}=.*\\R/m", $line, $env) : $env.$line;
        }

        return $env;
    }

    public function test_override_enumeration_covers_every_defined_connection(): void
    {
        $connections = self::databaseConnections();
        $this->assertContains('mariadb', $connections);
        $this->assertSame(['sqlite', 'mysql', 'mariadb', 'pgsql', 'sqlsrv'], $connections);
        foreach (array_diff($connections, ['mariadb']) as $connection) {
            $this->assertArrayHasKey("{$connection} connection", self::databaseDestinationOverrides());
        }
    }

    /** @param array<string, string> $values */
    #[DataProvider('databaseDestinationOverrides')]
    public function test_host_preparation_refuses_every_database_destination_override(array $values): void
    {
        $home = $this->scaffold();
        file_put_contents($home.'/.config/sample/deployment.env', self::pilotEnv($values));
        $result = $this->prepare($home);
        $this->assertFalse($result->isSuccessful());
        $this->assertStringNotContainsString('synthetic', $result->getErrorOutput());
        $this->assertDirectoryExists($home.'/sample-app');
    }

    public function test_empty_database_url_and_socket_preserve_the_isolated_local_configuration(): void
    {
        $home = $this->scaffold();
        file_put_contents($home.'/.config/sample/deployment.env', self::pilotEnv(['DB_URL' => '', 'DB_SOCKET' => '', 'DB_PORT' => '3306']));
        $this->assertTrue($this->prepare($home)->isSuccessful());
    }

    /** @param array<string, string> $environment */
    private function premigrate(string $env, array $environment = [], ?callable $prepare = null): Process
    {
        $home = $this->temporary.'/release-home';
        $release = $home.'/.deployments/sample-app/releases/release';
        foreach (['bootstrap/cache', 'storage/framework/cache', 'storage/framework/views', 'storage/logs'] as $path) {
            mkdir($release.'/'.$path, 0700, true);
        }
        foreach (['app', 'composer.json', 'config', 'database', 'public', 'resources', 'routes', 'vendor'] as $path) {
            symlink($this->root.'/'.$path, $release.'/'.$path);
        }
        foreach (['artisan', 'bootstrap/app.php', 'bootstrap/providers.php'] as $path) {
            copy($this->root.'/'.$path, $release.'/'.$path);
        }
        file_put_contents($release.'/.env', $env);
        if ($prepare !== null) {
            $prepare($release);
        }
        // Only what the test names reaches the hook; the suite's own database variables must not.
        $inherited = array_fill_keys(array_keys(getenv() + $_ENV), false);
        $process = new Process(['bash', $this->root.'/scripts/deploy/pilot/pre-migrate.sh',
            '.deployments/sample-app/releases/release', PHP_BINARY, 'sample-app'], null,
            array_replace($inherited, ['HOME' => $home, 'PATH' => (string) getenv('PATH')], $environment));
        $process->run();
        (new Process(['rm', '-rf', '--', $home]))->mustRun();

        return $process;
    }

    public function test_resolved_configuration_accepts_the_local_pilot_database(): void
    {
        $result = $this->premigrate(self::pilotEnv(['DB_URL' => '', 'DB_SOCKET' => '']));
        $this->assertTrue($result->isSuccessful(), $result->getErrorOutput());
        $this->assertStringContainsString('Resolved pilot database is the local server.', $result->getOutput());
    }

    /** @param array<string, string> $values */
    #[DataProvider('databaseDestinationOverrides')]
    public function test_resolved_configuration_refuses_every_database_destination_override(array $values): void
    {
        $result = $this->premigrate(self::pilotEnv($values));
        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('Resolved pilot database is not the local server', $result->getErrorOutput());
        $this->assertStringNotContainsString('synthetic', $result->getErrorOutput().$result->getOutput());
    }

    /**
     * Overrides the private file's key checks cannot see: Laravel reads them only when it resolves
     * the candidate's configuration on the host.
     *
     * @return array<string, array{string, array<string, string>, ?callable}>
     */
    public static function resolvedOnlyOverrides(): array
    {
        return [
            'host process environment wins over .env' => [self::PILOT_ENV, ['DB_HOST' => 'database.example.test'], null],
            'host process environment selects another connection' => [self::PILOT_ENV, ['DB_CONNECTION' => 'sqlite'], null],
            'cached configuration is not what migrations read' => [self::PILOT_ENV, [],
                static fn (string $release) => file_put_contents($release.'/bootstrap/cache/config.php', '<?php return [];')],
            'environment-specific file replaces .env' => [self::PILOT_ENV, ['APP_ENV' => 'sample'],
                static fn (string $release) => file_put_contents($release.'/.env.sample', "DB_CONNECTION=sqlite\nDB_DATABASE=/synthetic/another.sqlite\n")],
        ];
    }

    /** @param array<string, string> $environment */
    #[DataProvider('resolvedOnlyOverrides')]
    public function test_resolved_configuration_refuses_overrides_outside_the_private_file(string $env, array $environment, ?callable $prepare): void
    {
        $result = $this->premigrate($env, $environment, $prepare);
        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('Resolved pilot database is not the local server', $result->getErrorOutput());
    }

    public function test_pilot_opt_in_cannot_be_written_by_the_bootstrap_to_admit_a_wrong_target(): void
    {
        $home = $this->scaffold();
        file_put_contents($home.'/.config/sample/deployment.env', str_replace("PILOT_DEPLOYMENT=1\n", '', self::PILOT_ENV));
        $this->assertFalse($this->prepare($home)->isSuccessful());
        $this->assertDirectoryExists($home.'/sample-app');
    }

    public function test_real_existing_application_is_not_moved_and_activated_cron_is_not_silently_ignored(): void
    {
        $home = $this->scaffold();
        file_put_contents($home.'/sample-app/artisan', 'existing application');
        $this->assertTrue($this->prepare($home)->isSuccessful());
        $this->assertFileExists($home.'/sample-app/artisan');
        touch($home.'/.config/sample/cron-enabled');
        $this->assertFalse($this->prepare($home)->isSuccessful());
    }

    /** @return array<string, array{string}> */
    public static function phpExecutableNames(): array
    {
        return ['unversioned' => ['php'], 'versioned native' => ['php8.5'], 'CGI' => ['php-cgi8.5'], 'LSAPI' => ['lsphp85'], 'packaged' => ['ea-php85']];
    }

    #[DataProvider('phpExecutableNames')]
    public function test_quiescence_waits_for_own_artisan_but_ignores_other_accounts(string $binaryName): void
    {
        $proc = $this->temporary.'/proc/123';
        mkdir($proc, 0700, true);
        $binary = $this->temporary.'/'.$binaryName;
        touch($binary);
        symlink($binary, $proc.'/exe');
        file_put_contents($proc.'/cmdline', "php\0artisan\0queue:work\0");
        file_put_contents($proc.'/status', "Uid:\t1234\t1234\t1234\t1234\n");
        $command = ['bash', $this->root.'/scripts/deploy/pilot/quiesce.sh',
            '.deployments/sample-app/releases/release', PHP_BINARY, 'sample-app'];
        $env = ['PILOT_QUIESCE_PROC_ROOT' => dirname($proc), 'PILOT_QUIESCE_UID' => '1234', 'PILOT_QUIESCE_TIMEOUT_SECONDS' => '0'];
        $own = new Process($command, null, $env);
        $own->run();
        $this->assertFalse($own->isSuccessful(), $own->getErrorOutput());
        $other = new Process($command, null, array_replace($env, ['PILOT_QUIESCE_UID' => '5678']));
        $other->run();
        $this->assertTrue($other->isSuccessful(), $other->getErrorOutput());
    }

    public function test_public_acl_restoration_grants_only_public_reads_and_refuses_private_symlinks(): void
    {
        $home = $this->scaffold();
        $bin = $this->temporary.'/bin';
        mkdir($bin);
        $log = $this->temporary.'/acl.log';
        file_put_contents($bin.'/setfacl', "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> \"\$PILOT_ACL_LOG\"\n");
        chmod($bin.'/setfacl', 0700);
        $env = ['HOME' => $home, 'PATH' => $bin.':'.getenv('PATH'), 'PILOT_ACL_LOG' => $log];
        $command = ['bash', $this->root.'/scripts/deploy/pilot/post-activate.sh',
            'sample-app', PHP_BINARY, '.deployments/sample-app/releases/release'];
        $private = $home.'/.deployments/sample-app/shared/storage/app/private';
        mkdir($private, 0700, true);
        file_put_contents($private.'/protected.key', 'synthetic key');
        $public = $home.'/.deployments/sample-app/shared/public/viewer';
        mkdir($public, 0700, true);
        file_put_contents($public.'/asset.js', 'synthetic asset');
        symlink($public, $home.'/sample-app/public/viewer');
        $process = new Process($command, null, $env);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $calls = file_get_contents($log);
        $this->assertStringContainsString('u:nginx:r-- '.$public.'/asset.js', $calls);
        $this->assertStringNotContainsString('/storage/app/private', $calls);
        $this->assertStringNotContainsString('sample-app/.env', $calls);
        symlink($private, $home.'/sample-app/public/unsafe');
        $process = new Process($command, null, $env);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringNotContainsString('/storage/app/private', file_get_contents($log));
    }

    public function test_release_manifest_binds_operator_source_tooling_and_individual_run(): void
    {
        mkdir($this->temporary.'/public');
        $env = ['PILOT_OPERATOR_SHA' => str_repeat('a', 40), 'PILOT_APPLICATION_SHA' => str_repeat('b', 40),
            'PILOT_TOOLING_SHA' => str_repeat('c', 40), 'GITHUB_RUN_ID' => '42', 'GITHUB_RUN_ATTEMPT' => '2'];
        $process = new Process(['node', $this->root.'/scripts/deploy/pilot/manifest.mjs'], $this->temporary, $env);
        $process->mustRun();
        $manifest = json_decode(file_get_contents($this->temporary.'/public/pilot-source.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(str_repeat('b', 40), $manifest['application_revision']);
        $this->assertSame('aaaaaaaaaaaa-42-2', $manifest['deployment_id']);
        $source = <<<'JS'
import {pathToFileURL} from 'node:url';
const manifest = JSON.parse(process.argv[2]);
globalThis.fetch = async () => ({ok:true,json:async()=>manifest});
try {await import(pathToFileURL(process.argv[1]));} catch {process.exitCode=1;}
JS;
        $env += ['DEPLOY_SITE_URL' => 'https://pilot.example.test', 'DEPLOY_RELEASE_ID' => $manifest['deployment_id']];
        $verify = fn (array $data): Process => new Process(['node', '--input-type=module', '-e', $source,
            $this->root.'/scripts/deploy/pilot/verify-live.mjs', json_encode($data, JSON_THROW_ON_ERROR)], null, $env);
        $good = $verify($manifest);
        $good->run();
        $this->assertTrue($good->isSuccessful(), $good->getErrorOutput());
        $wrong = $verify(array_replace($manifest, ['application_revision' => str_repeat('d', 40)]));
        $wrong->run();
        $this->assertFalse($wrong->isSuccessful());
    }

    public function test_each_pilot_workflow_is_manual_only_and_uses_separate_native_target_settings(): void
    {
        foreach (['pilot-deploy.yml' => 'pilot'] as $file => $environment) {
            $workflow = Yaml::parseFile($this->root.'/.github/workflows/'.$file);
            $on = $workflow[true] ?? $workflow['on'];
            $this->assertSame(['workflow_dispatch'], array_keys($on));
            $this->assertFalse($on['workflow_dispatch']['inputs']['deploy']['default']);
            $this->assertSame($environment, $workflow['jobs']['deploy']['environment']);
            $this->assertSame('ubuntu-24.04-arm', $workflow['jobs']['deploy']['runs-on']);
            $this->assertLessThanOrEqual(30, $workflow['jobs']['deploy']['timeout-minutes']);
            $text = file_get_contents($this->root.'/.github/workflows/'.$file);
            $this->assertStringNotContainsString('secrets.SSH_', $text);
            $this->assertStringNotContainsString('environment: prod', $text);
            $this->assertStringContainsString('set-php-handler: false', $text);
            $this->assertStringContainsString('install-cron: false', $text);
            $this->assertStringContainsString('php-binary: /usr/bin/php', $text);
        }
    }

    public function test_workflow_pins_the_database_destination_and_checks_resolved_configuration_before_migration(): void
    {
        foreach (glob($this->root.'/.github/workflows/pilot*.yml') as $file) {
            $steps = Yaml::parseFile($file)['jobs']['deploy']['steps'];
            $publish = array_values(array_filter($steps, fn (array $step): bool => preg_match('~^bherila/shared-cpanel-deployment@[0-9a-f]{40}$~', $step['uses'] ?? '') === 1));
            $this->assertCount(1, $publish);
            $with = $publish[0]['with'];
            $lines = fn (string $input): array => array_values(array_filter(array_map('trim', explode("\n", $with[$input] ?? ''))));
            $this->assertSame(['DB_URL=', 'DB_SOCKET='], $lines('env-values'));
            foreach (['DB_URL=', 'DB_SOCKET=', 'DB_CONNECTION=mariadb', 'DB_HOST=127.0.0.1', 'PILOT_DEPLOYMENT=1', 'APP_DEBUG=false', 'MAIL_MAILER=log'] as $assertion) {
                $this->assertContains($assertion, $lines('env-assert'));
            }
            // The action installs .env, enters the risk boundary, runs pre-migrate-script, then migrates.
            $this->assertSame('atomic', $with['deployment-mode']);
            $this->assertSame('after-upload', $with['migration-order'] ?? 'after-upload');
            $this->assertSame('scripts/deploy/pilot/pre-migrate.sh', $with['pre-migrate-script']);
            $hook = (string) file_get_contents($this->root.'/'.$with['pre-migrate-script']);
            $this->assertStringContainsString('$default !== "mariadb"', $hook);
            $this->assertStringContainsString('($connection["host"] ?? null) !== "127.0.0.1"', $hook);
            $this->assertStringContainsString('($env["DB_CONNECTION"] ?? "") !== "mariadb"', (string) file_get_contents($this->root.'/scripts/deploy/pilot/prepare-host.sh'));
        }
    }
}
