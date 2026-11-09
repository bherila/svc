<?php

/**
 * The enforcing dependency audit.
 *
 *   php scripts/ci/dependency-audit.php [--exceptions=<file>] [--today=YYYY-MM-DD]
 *                                       [--composer-command=<shell>] [--pnpm-command=<shell>]
 *
 * Runs `composer audit --locked --format=json` and `pnpm audit --prod --json`, and fails on any
 * advisory not covered by an unexpired entry in the exceptions file (default
 * .github/audit-exceptions.json). The two command options exist so a test can stand in fixture
 * output for the real tools.
 *
 * Exit status, one per kind of failure so a reader of the CI log knows who has to act:
 *   0  no advisory outside the exceptions
 *   1  advisories found (or an exception has expired and no longer excuses its advisory)
 *   2  the audit could not run: no usable report from a tool. Nothing is known; retry or fix the tooling
 *   3  the exceptions file is invalid
 *
 * The decisions live in scripts/ci/DependencyAudit.php.
 */

declare(strict_types=1);

require __DIR__.'/DependencyAudit.php';

use Svc\Ci\AuditUnavailable;
use Svc\Ci\DependencyAudit;
use Svc\Ci\InvalidExceptions;

$root = dirname(__DIR__, 2);
$options = ['exceptions' => $root.'/.github/audit-exceptions.json', 'today' => gmdate('Y-m-d'),
    'composer-command' => 'composer audit --locked --format=json --abandoned=ignore --no-interaction',
    'pnpm-command' => 'pnpm audit --prod --json'];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([a-z-]+)=(.*)\z/s', $argument, $match) !== 1 || ! array_key_exists($match[1], $options)) {
        fwrite(STDERR, "dependency-audit: unknown argument {$argument}\n");
        exit(3);
    }
    $options[$match[1]] = $match[2];
}

/** @return array{string, int, string} stdout, exit status, stderr */
function audit_run(string $command, string $cwd): array
{
    $process = proc_open(['sh', '-c', $command], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (! is_resource($process)) {
        return ['', 127, 'could not start the command'];
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [$stdout, proc_close($process), $stderr];
}

function audit_line(array $finding): string
{
    return sprintf('  - [%s] %s: %s (%s) %s %s', $finding['source'], $finding['package'], $finding['title'] ?: 'untitled',
        implode(', ', $finding['ids']) ?: 'no id', $finding['severity'] !== '' ? 'severity '.$finding['severity'] : '', $finding['url']);
}

if (! is_file($options['exceptions'])) {
    fwrite(STDERR, "dependency-audit: INVALID EXCEPTIONS: {$options['exceptions']} does not exist.\n");
    exit(3);
}
try {
    $exceptions = DependencyAudit::parseExceptions((string) file_get_contents($options['exceptions']));
} catch (InvalidExceptions $exception) {
    fwrite(STDERR, 'dependency-audit: INVALID EXCEPTIONS: '.$exception->getMessage()."\n");
    exit(3);
}
if (preg_match('/^\d{4}-\d{2}-\d{2}\z/', $options['today']) !== 1) {
    fwrite(STDERR, "dependency-audit: --today must be YYYY-MM-DD.\n");
    exit(3);
}

$findings = [];
try {
    [$out, $status, $err] = audit_run($options['composer-command'], $root);
    $findings = [...$findings, ...DependencyAudit::parseComposer($out, $status)];
    [$out, $status, $err] = audit_run($options['pnpm-command'], $root);
    $findings = [...$findings, ...DependencyAudit::parsePnpm($out, $status)];
} catch (AuditUnavailable $exception) {
    fwrite(STDERR, 'dependency-audit: AUDIT COULD NOT RUN (this is not an advisory; nothing was checked): '.$exception->getMessage()."\n");
    if (trim($err) !== '') {
        fwrite(STDERR, 'The tool said: '.substr(trim($err), 0, 500)."\n");
    }
    exit(2);
}

$result = DependencyAudit::evaluate($findings, $exceptions, $options['today']);

foreach ($result['excepted'] as $entry) {
    echo 'excepted until '.$entry['exception']['expires'].' ('.$entry['exception']['reason'].'):'."\n".audit_line($entry['finding'])."\n";
}
foreach ($result['unused'] as $exception) {
    echo "note: the exception for {$exception['id']} in {$exception['package']} matches no advisory now; remove it.\n";
}

if ($result['expired'] !== []) {
    fwrite(STDERR, "dependency-audit: EXPIRED EXCEPTIONS - the date has passed, so these advisories block again. Update the package, or renew the exception with a new reason and date:\n");
    foreach ($result['expired'] as $entry) {
        fwrite(STDERR, audit_line($entry['finding'])."\n    exception expired {$entry['exception']['expires']}: {$entry['exception']['reason']}\n");
    }
}
if ($result['blocking'] !== []) {
    fwrite(STDERR, "dependency-audit: ADVISORIES FOUND - update the package, or add an entry with a reason and an expiry to .github/audit-exceptions.json:\n");
    foreach ($result['blocking'] as $finding) {
        fwrite(STDERR, audit_line($finding)."\n");
    }
}
if ($result['blocking'] !== [] || $result['expired'] !== []) {
    exit(1);
}

echo 'dependency-audit: no advisory outside the exceptions ('.count($findings)." found, {$options['today']}).\n";
exit(0);
