<?php

namespace Tests\Unit\Deployment;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Svc\Ci\AuditUnavailable;
use Svc\Ci\DependencyAudit;
use Svc\Ci\InvalidExceptions;

/**
 * The enforcing dependency audit: the decision class on fixture reports, and the CLI end to end with
 * the real tools replaced by fixture output. Every report here is synthetic.
 */
final class DependencyAuditTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 3).'/scripts/ci/DependencyAudit.php';
        $this->directory = sys_get_temp_dir().'/svc-audit-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    private static function composerClean(): string
    {
        return json_encode(['advisories' => [], 'abandoned' => []], JSON_THROW_ON_ERROR);
    }

    private static function composerAdvisory(): string
    {
        return json_encode(['advisories' => ['synthetic/package' => [[
            'advisoryId' => 'PKSA-synthetic-0001',
            'packageName' => 'synthetic/package',
            'title' => 'Synthetic request smuggling',
            'cve' => 'CVE-2099-0001',
            'link' => 'https://advisories.example.test/synthetic-1',
            'severity' => 'high',
            'sources' => [['name' => 'GitHub', 'remoteId' => 'GHSA-syn1-syn1-syn1']],
        ]]], 'abandoned' => []], JSON_THROW_ON_ERROR);
    }

    private static function pnpmClean(): string
    {
        return json_encode(['advisories' => new \stdClass, 'metadata' => ['vulnerabilities' => ['high' => 0]]], JSON_THROW_ON_ERROR);
    }

    private static function pnpmAdvisory(): string
    {
        return json_encode(['advisories' => ['1099999' => [
            'id' => 1099999, 'module_name' => 'synthetic-module', 'title' => 'Synthetic prototype pollution', 'severity' => 'moderate',
            'github_advisory_id' => 'GHSA-syn2-syn2-syn2', 'cves' => ['CVE-2099-0002'], 'url' => 'https://advisories.example.test/synthetic-2',
        ]], 'metadata' => ['vulnerabilities' => ['moderate' => 1]]], JSON_THROW_ON_ERROR);
    }

    public function test_clean_reports_have_no_findings(): void
    {
        $this->assertSame([], DependencyAudit::parseComposer(self::composerClean(), 0));
        $this->assertSame([], DependencyAudit::parsePnpm(self::pnpmClean(), 0));
        // Abandoned packages alone (status 2) are not an advisory.
        $this->assertSame([], DependencyAudit::parseComposer(self::composerClean(), 2));
    }

    public function test_an_advisory_is_read_with_every_identifier_it_can_be_excepted_by(): void
    {
        $composer = DependencyAudit::parseComposer(self::composerAdvisory(), 1);
        $this->assertSame([[
            'source' => 'composer', 'package' => 'synthetic/package',
            'ids' => ['PKSA-synthetic-0001', 'CVE-2099-0001', 'GHSA-syn1-syn1-syn1'],
            'title' => 'Synthetic request smuggling', 'severity' => 'high', 'url' => 'https://advisories.example.test/synthetic-1',
        ]], $composer);

        $pnpm = DependencyAudit::parsePnpm(self::pnpmAdvisory(), 1);
        $this->assertSame([[
            'source' => 'pnpm', 'package' => 'synthetic-module',
            'ids' => ['GHSA-syn2-syn2-syn2', '1099999', 'CVE-2099-0002'],
            'title' => 'Synthetic prototype pollution', 'severity' => 'moderate', 'url' => 'https://advisories.example.test/synthetic-2',
        ]], $pnpm);
    }

    /** @return array<string, array{string, string, int}> */
    public static function unusableReports(): array
    {
        return [
            'composer: no output' => ['composer', '', 1],
            'composer: an error message' => ['composer', 'Could not resolve host: repo.packagist.org', 1],
            'composer: another json document' => ['composer', '{"error":"x"}', 0],
            'composer: advisories is not a list' => ['composer', '{"advisories":"x"}', 0],
            'composer: a package whose advisories are not a list' => ['composer', '{"advisories":{"a/b":"x"}}', 1],
            'composer: an advisory that is not an object' => ['composer', '{"advisories":{"a/b":["x"]}}', 1],
            'composer: crashed' => ['composer', '{"advisories":[]}', 255],
            'composer: negative status' => ['composer', '{"advisories":[]}', -1],
            'composer: vulnerable status but nothing listed' => ['composer', '{"advisories":[]}', 1],
            'pnpm: no output' => ['pnpm', '', 1],
            'pnpm: a network error' => ['pnpm', '{"error":{"code":"ERR_PNPM_AUDIT_BAD_RESPONSE","message":"offline"}}', 1],
            'pnpm: another json document' => ['pnpm', '{"metadata":{}}', 0],
            'pnpm: advisories is not a list' => ['pnpm', '{"advisories":1}', 0],
            'pnpm: an advisory that is not an object' => ['pnpm', '{"advisories":{"1":"x"}}', 1],
            'pnpm: crashed' => ['pnpm', '{"advisories":{}}', 2],
            'pnpm: vulnerable status but nothing listed' => ['pnpm', '{"advisories":{}}', 1],
        ];
    }

    /** "The audit could not run" is never read as "no advisories". */
    #[DataProvider('unusableReports')]
    public function test_an_unusable_report_means_the_audit_could_not_run(string $tool, string $stdout, int $status): void
    {
        $this->expectException(AuditUnavailable::class);

        $tool === 'composer' ? DependencyAudit::parseComposer($stdout, $status) : DependencyAudit::parsePnpm($stdout, $status);
    }

    public function test_an_advisory_without_an_exception_blocks(): void
    {
        $findings = DependencyAudit::parseComposer(self::composerAdvisory(), 1);

        $result = DependencyAudit::evaluate($findings, [], '2026-11-08');

        $this->assertSame($findings, $result['blocking']);
        $this->assertSame([], $result['excepted']);
        $this->assertSame([], $result['expired']);
    }

    public function test_an_exception_excuses_its_advisory_through_its_expiry_day_by_any_identifier(): void
    {
        $findings = DependencyAudit::parseComposer(self::composerAdvisory(), 1);
        foreach (['PKSA-synthetic-0001', 'CVE-2099-0001', 'GHSA-syn1-syn1-syn1'] as $id) {
            $exception = ['id' => $id, 'package' => 'synthetic/package', 'reason' => 'Synthetic: not reachable', 'expires' => '2026-12-01'];

            foreach (['2026-11-08', '2026-12-01'] as $today) {
                $result = DependencyAudit::evaluate($findings, [$exception], $today);
                $this->assertSame([], $result['blocking'], "$id on $today");
                $this->assertSame([], $result['expired'], "$id on $today");
                $this->assertSame([['finding' => $findings[0], 'exception' => $exception]], $result['excepted'], "$id on $today");
                $this->assertSame([], $result['unused']);
            }
        }
    }

    public function test_an_expired_exception_no_longer_excuses_and_is_reported_as_expired(): void
    {
        $findings = DependencyAudit::parsePnpm(self::pnpmAdvisory(), 1);
        $exception = ['id' => 'GHSA-syn2-syn2-syn2', 'package' => 'synthetic-module', 'reason' => 'Synthetic', 'expires' => '2026-11-07'];

        $result = DependencyAudit::evaluate($findings, [$exception], '2026-11-08');

        $this->assertSame([], $result['blocking']);
        $this->assertSame([], $result['excepted']);
        $this->assertSame([['finding' => $findings[0], 'exception' => $exception]], $result['expired']);
    }

    public function test_an_exception_must_match_both_the_advisory_and_the_package(): void
    {
        $findings = DependencyAudit::parseComposer(self::composerAdvisory(), 1);
        $wrongPackage = ['id' => 'PKSA-synthetic-0001', 'package' => 'other/package', 'reason' => 'Synthetic', 'expires' => '2099-01-01'];
        $wrongId = ['id' => 'PKSA-other', 'package' => 'synthetic/package', 'reason' => 'Synthetic', 'expires' => '2099-01-01'];

        $result = DependencyAudit::evaluate($findings, [$wrongPackage, $wrongId], '2026-11-08');

        $this->assertSame($findings, $result['blocking']);
        $this->assertSame([$wrongPackage, $wrongId], $result['unused']);
    }

    public function test_one_exception_does_not_excuse_a_different_advisory_in_the_same_package(): void
    {
        $other = ['source' => 'composer', 'package' => 'synthetic/package', 'ids' => ['PKSA-second'], 'title' => 'Second', 'severity' => '', 'url' => ''];
        $findings = [...DependencyAudit::parseComposer(self::composerAdvisory(), 1), $other];
        $exception = ['id' => 'PKSA-synthetic-0001', 'package' => 'synthetic/package', 'reason' => 'Synthetic', 'expires' => '2099-01-01'];

        $result = DependencyAudit::evaluate($findings, [$exception], '2026-11-08');

        $this->assertSame([$other], $result['blocking']);
        $this->assertCount(1, $result['excepted']);
    }

    public function test_exceptions_are_validated_strictly(): void
    {
        $good = ['id' => 'GHSA-x', 'package' => 'a/b', 'reason' => 'Synthetic reason', 'expires' => '2026-12-01'];
        $this->assertSame([$good], DependencyAudit::parseExceptions(json_encode(['exceptions' => [[...$good, 'id' => ' GHSA-x ', 'reason' => ' Synthetic reason ']]], JSON_THROW_ON_ERROR)));
        $this->assertSame([], DependencyAudit::parseExceptions('{"exceptions":[]}'));

        $bad = [
            'not json' => 'nope',
            'not an object' => '[]',
            'exceptions is not a list' => '{"exceptions":{"a":1}}',
            'exceptions missing' => '{}',
            'an entry that is not an object' => '{"exceptions":["x"]}',
            'no id' => json_encode(['exceptions' => [[...$good, 'id' => '']]]),
            'no package' => json_encode(['exceptions' => [array_diff_key($good, ['package' => 1])]]),
            'no reason' => json_encode(['exceptions' => [[...$good, 'reason' => '  ']]]),
            'no expiry' => json_encode(['exceptions' => [array_diff_key($good, ['expires' => 1])]]),
            'expiry not a string' => json_encode(['exceptions' => [[...$good, 'expires' => 20261201]]]),
            'expiry in another format' => json_encode(['exceptions' => [[...$good, 'expires' => '12/01/2026']]]),
            'expiry that is not a date' => json_encode(['exceptions' => [[...$good, 'expires' => '2026-02-30']]]),
        ];
        foreach ($bad as $name => $json) {
            try {
                DependencyAudit::parseExceptions((string) $json);
                $this->fail("$name should be refused.");
            } catch (InvalidExceptions) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * @param  array{composer?: array{string, int}, pnpm?: array{string, int}}  $outputs
     * @return array{int, string, string} status, stdout, stderr
     */
    private function cli(array $outputs, ?string $exceptions = null, string $today = '2026-11-08'): array
    {
        $files = [];
        foreach (['composer' => [self::composerClean(), 0], 'pnpm' => [self::pnpmClean(), 0], ...$outputs] as $tool => [$stdout, $status]) {
            file_put_contents($this->directory.'/'.$tool.'.out', $stdout);
            $files[$tool] = 'cat '.escapeshellarg($this->directory.'/'.$tool.'.out').'; exit '.$status;
        }
        file_put_contents($this->directory.'/exceptions.json', $exceptions ?? '{"exceptions":[]}');

        $process = proc_open([PHP_BINARY, dirname(__DIR__, 3).'/scripts/ci/dependency-audit.php',
            '--exceptions='.$this->directory.'/exceptions.json', '--today='.$today,
            '--composer-command='.$files['composer'], '--pnpm-command='.$files['pnpm']], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    public function test_cli_clean_audit_passes(): void
    {
        [$status, $stdout] = $this->cli([]);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('no advisory outside the exceptions (0 found, 2026-11-08)', $stdout);
    }

    public function test_cli_advisory_fails_with_status_1_naming_the_package_and_how_to_fix_it(): void
    {
        [$status, , $stderr] = $this->cli(['composer' => [self::composerAdvisory(), 1]]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('ADVISORIES FOUND', $stderr);
        $this->assertStringContainsString('synthetic/package', $stderr);
        $this->assertStringContainsString('PKSA-synthetic-0001', $stderr);
        $this->assertStringContainsString('.github/audit-exceptions.json', $stderr);
        $this->assertStringNotContainsString('COULD NOT RUN', $stderr);
    }

    public function test_cli_a_pnpm_advisory_fails_too(): void
    {
        [$status, , $stderr] = $this->cli(['pnpm' => [self::pnpmAdvisory(), 1]]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('synthetic-module', $stderr);
    }

    public function test_cli_an_excepted_advisory_passes_and_says_why(): void
    {
        $exceptions = json_encode(['exceptions' => [
            ['id' => 'GHSA-syn1-syn1-syn1', 'package' => 'synthetic/package', 'reason' => 'Synthetic: dev-only path', 'expires' => '2026-12-31'],
            ['id' => 'GHSA-gone', 'package' => 'gone/package', 'reason' => 'Synthetic: stale', 'expires' => '2026-12-31'],
        ]], JSON_THROW_ON_ERROR);

        [$status, $stdout, $stderr] = $this->cli(['composer' => [self::composerAdvisory(), 1]], $exceptions);

        $this->assertSame(0, $status, $stderr);
        $this->assertStringContainsString('excepted until 2026-12-31 (Synthetic: dev-only path)', $stdout);
        $this->assertStringContainsString('the exception for GHSA-gone in gone/package matches no advisory now; remove it.', $stdout);
        $this->assertSame('', $stderr);
    }

    public function test_cli_an_expired_exception_fails_with_its_own_message(): void
    {
        $exceptions = json_encode(['exceptions' => [
            ['id' => 'GHSA-syn1-syn1-syn1', 'package' => 'synthetic/package', 'reason' => 'Synthetic: dev-only path', 'expires' => '2026-11-07'],
        ]], JSON_THROW_ON_ERROR);

        [$status, , $stderr] = $this->cli(['composer' => [self::composerAdvisory(), 1]], $exceptions);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('EXPIRED EXCEPTIONS', $stderr);
        $this->assertStringContainsString('exception expired 2026-11-07: Synthetic: dev-only path', $stderr);
        $this->assertStringNotContainsString('ADVISORIES FOUND', $stderr);
    }

    /** @return array<string, array{string, array{string, int}, string}> */
    public static function toolFailures(): array
    {
        return [
            'composer cannot reach the network' => ['composer', ['Could not resolve host: repo.packagist.org', 1], 'composer audit printed no JSON report'],
            'composer is not installed' => ['composer', ['', 127], 'composer audit printed no JSON report'],
            'pnpm reports a registry error' => ['pnpm', ['{"error":{"code":"ERR_PNPM_AUDIT_BAD_RESPONSE"}}', 1], 'pnpm audit reported an error instead of a report'],
            'pnpm is not installed' => ['pnpm', ['', 127], 'pnpm audit printed no JSON report'],
        ];
    }

    /** @param  array{string, int}  $output */
    #[DataProvider('toolFailures')]
    public function test_cli_a_tool_that_cannot_run_fails_with_status_2_and_a_message_that_is_not_about_advisories(string $tool, array $output, string $reason): void
    {
        [$status, , $stderr] = $this->cli([$tool => $output]);

        $this->assertSame(2, $status);
        $this->assertStringContainsString('AUDIT COULD NOT RUN (this is not an advisory; nothing was checked)', $stderr);
        $this->assertStringContainsString($reason, $stderr);
        $this->assertStringNotContainsString('ADVISORIES FOUND', $stderr);
    }

    public function test_cli_a_tool_that_cannot_run_hides_nothing_even_when_the_other_found_advisories(): void
    {
        [$status, , $stderr] = $this->cli(['composer' => [self::composerAdvisory(), 1], 'pnpm' => ['', 127]]);

        $this->assertSame(2, $status);
        $this->assertStringContainsString('COULD NOT RUN', $stderr);
    }

    public function test_cli_an_invalid_exceptions_file_fails_with_status_3(): void
    {
        foreach (['not json', '{"exceptions":[{"id":"x"}]}'] as $invalid) {
            [$status, , $stderr] = $this->cli([], $invalid);

            $this->assertSame(3, $status);
            $this->assertStringContainsString('INVALID EXCEPTIONS', $stderr);
        }

        $process = proc_open([PHP_BINARY, dirname(__DIR__, 3).'/scripts/ci/dependency-audit.php', '--exceptions='.$this->directory.'/missing.json'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $stderr = (string) stream_get_contents($pipes[2]);
        $this->assertSame(3, proc_close($process));
        $this->assertStringContainsString('does not exist', $stderr);
    }

    public function test_cli_refuses_arguments_it_does_not_know(): void
    {
        file_put_contents($this->directory.'/e.json', '{"exceptions":[]}');
        foreach (['--nope=1', 'positional', '--today=11/08/2026'] as $argument) {
            $process = proc_open([PHP_BINARY, dirname(__DIR__, 3).'/scripts/ci/dependency-audit.php', '--exceptions='.$this->directory.'/e.json', $argument], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            stream_get_contents($pipes[2]);
            $this->assertSame(3, proc_close($process), $argument);
        }
    }
}
