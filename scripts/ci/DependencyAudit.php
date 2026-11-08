<?php

declare(strict_types=1);

namespace Svc\Ci;

use InvalidArgumentException;
use RuntimeException;

/**
 * The decision logic of the `dependency-audit` CI lane, kept apart from the process handling in
 * scripts/ci/dependency-audit.php so a test can feed it fixture JSON.
 *
 * Three outcomes are kept distinct on purpose, because each needs a different person to act:
 *   - the audit could not run (no network, a tool crashed, output that is not a report):
 *     {@see AuditUnavailable}; nothing is known about the dependencies, so the lane fails;
 *   - the exceptions file is unusable: {@see InvalidExceptions};
 *   - advisories were found: reported by {@see self::evaluate()}, split into blocking ones,
 *     excepted ones and ones whose exception has expired.
 *
 * An exception names an advisory id AND the package it applies to, carries a reason and expires on
 * a date (valid through that day). An expired exception does not excuse its advisory.
 */
final class DependencyAudit
{
    /**
     * @return list<array{source: string, package: string, ids: list<string>, title: string, severity: string, url: string}>
     */
    public static function parseComposer(string $stdout, int $exitCode): array
    {
        $report = self::decode('composer audit', $stdout);
        if (! array_key_exists('advisories', $report) || ! is_array($report['advisories'])) {
            throw new AuditUnavailable('composer audit printed JSON that is not an audit report (no "advisories").');
        }
        // 0 clean, 1 vulnerable; 2 and 3 add abandoned packages, which this lane does not judge.
        if ($exitCode < 0 || $exitCode > 3) {
            throw new AuditUnavailable("composer audit exited with status {$exitCode}.");
        }

        $findings = [];
        foreach ($report['advisories'] as $package => $advisories) {
            if (! is_array($advisories)) {
                throw new AuditUnavailable('composer audit printed an advisory list that is not a list.');
            }
            foreach ($advisories as $advisory) {
                if (! is_array($advisory)) {
                    throw new AuditUnavailable('composer audit printed an advisory that is not an object.');
                }
                $ids = [self::string($advisory['advisoryId'] ?? null), self::string($advisory['cve'] ?? null)];
                foreach (is_array($advisory['sources'] ?? null) ? $advisory['sources'] : [] as $source) {
                    $ids[] = is_array($source) ? self::string($source['remoteId'] ?? null) : '';
                }
                $findings[] = [
                    'source' => 'composer',
                    'package' => self::string($advisory['packageName'] ?? null) ?: (string) $package,
                    'ids' => self::ids($ids),
                    'title' => self::string($advisory['title'] ?? null),
                    'severity' => self::string($advisory['severity'] ?? null),
                    'url' => self::string($advisory['link'] ?? null),
                ];
            }
        }
        if ($findings === [] && ($exitCode & 1) === 1) {
            throw new AuditUnavailable('composer audit exited as vulnerable but its report lists no advisory.');
        }

        return $findings;
    }

    /**
     * @return list<array{source: string, package: string, ids: list<string>, title: string, severity: string, url: string}>
     */
    public static function parsePnpm(string $stdout, int $exitCode): array
    {
        $report = self::decode('pnpm audit', $stdout);
        if (array_key_exists('error', $report)) {
            throw new AuditUnavailable('pnpm audit reported an error instead of a report.');
        }
        if (! array_key_exists('advisories', $report) || ! is_array($report['advisories'])) {
            throw new AuditUnavailable('pnpm audit printed JSON that is not an audit report (no "advisories").');
        }
        if ($exitCode !== 0 && $exitCode !== 1) {
            throw new AuditUnavailable("pnpm audit exited with status {$exitCode}.");
        }

        $findings = [];
        foreach ($report['advisories'] as $advisory) {
            if (! is_array($advisory)) {
                throw new AuditUnavailable('pnpm audit printed an advisory that is not an object.');
            }
            $ids = [self::string($advisory['github_advisory_id'] ?? null), self::string($advisory['id'] ?? null)];
            foreach (is_array($advisory['cves'] ?? null) ? $advisory['cves'] : [] as $cve) {
                $ids[] = self::string($cve);
            }
            $findings[] = [
                'source' => 'pnpm',
                'package' => self::string($advisory['module_name'] ?? null),
                'ids' => self::ids($ids),
                'title' => self::string($advisory['title'] ?? null),
                'severity' => self::string($advisory['severity'] ?? null),
                'url' => self::string($advisory['url'] ?? null),
            ];
        }
        if ($findings === [] && $exitCode === 1) {
            throw new AuditUnavailable('pnpm audit exited as vulnerable but its report lists no advisory.');
        }

        return $findings;
    }

    /**
     * @return list<array{id: string, package: string, reason: string, expires: string}>
     */
    public static function parseExceptions(string $json): array
    {
        $document = json_decode($json, true);
        if (! is_array($document) || ! isset($document['exceptions']) || ! is_array($document['exceptions']) || ! array_is_list($document['exceptions'])) {
            throw new InvalidExceptions('the exceptions file must be a JSON object with an "exceptions" list.');
        }

        $exceptions = [];
        foreach ($document['exceptions'] as $position => $entry) {
            $at = 'exception #'.($position + 1);
            if (! is_array($entry)) {
                throw new InvalidExceptions("{$at} is not an object.");
            }
            foreach (['id', 'package', 'reason', 'expires'] as $field) {
                if (! is_string($entry[$field] ?? null) || trim($entry[$field]) === '') {
                    throw new InvalidExceptions("{$at} needs a non-empty \"{$field}\".");
                }
            }
            $expires = $entry['expires'];
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $expires);
            if ($date === false || $date->format('Y-m-d') !== $expires) {
                throw new InvalidExceptions("{$at} has \"expires\" {$expires}; it must be a real date as YYYY-MM-DD.");
            }
            $exceptions[] = ['id' => trim($entry['id']), 'package' => trim($entry['package']), 'reason' => trim($entry['reason']), 'expires' => $expires];
        }

        return $exceptions;
    }

    /**
     * @param  list<array{source: string, package: string, ids: list<string>, title: string, severity: string, url: string}>  $findings
     * @param  list<array{id: string, package: string, reason: string, expires: string}>  $exceptions
     * @param  string  $today  YYYY-MM-DD; an exception is valid through its expiry day.
     * @return array{
     *     blocking: list<array{source: string, package: string, ids: list<string>, title: string, severity: string, url: string}>,
     *     excepted: list<array{finding: array{source: string, package: string, ids: list<string>, title: string, severity: string, url: string}, exception: array{id: string, package: string, reason: string, expires: string}}>,
     *     expired: list<array{finding: array{source: string, package: string, ids: list<string>, title: string, severity: string, url: string}, exception: array{id: string, package: string, reason: string, expires: string}}>,
     *     unused: list<array{id: string, package: string, reason: string, expires: string}>
     * }
     */
    public static function evaluate(array $findings, array $exceptions, string $today): array
    {
        $blocking = [];
        $excepted = [];
        $expired = [];
        $used = [];

        foreach ($findings as $finding) {
            $match = null;
            foreach ($exceptions as $position => $exception) {
                if ($exception['package'] === $finding['package'] && in_array($exception['id'], $finding['ids'], true)) {
                    $match = $exception;
                    $used[$position] = true;
                    break;
                }
            }

            if ($match === null) {
                $blocking[] = $finding;
            } elseif ($match['expires'] < $today) {
                $expired[] = ['finding' => $finding, 'exception' => $match];
            } else {
                $excepted[] = ['finding' => $finding, 'exception' => $match];
            }
        }

        $unused = [];
        foreach ($exceptions as $position => $exception) {
            if (! isset($used[$position])) {
                $unused[] = $exception;
            }
        }

        return ['blocking' => $blocking, 'excepted' => $excepted, 'expired' => $expired, 'unused' => $unused];
    }

    /** @return array<mixed> */
    private static function decode(string $tool, string $stdout): array
    {
        $decoded = json_decode(trim($stdout), true);
        if (! is_array($decoded)) {
            throw new AuditUnavailable("{$tool} printed no JSON report.");
        }

        return $decoded;
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : (is_int($value) ? (string) $value : '');
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter($ids, static fn (string $id): bool => $id !== '')));
    }
}

/** The audit produced no usable report: no network, a crashed tool, output that is not a report. */
final class AuditUnavailable extends RuntimeException {}

/** The exceptions file cannot be trusted, so nothing can be excused. */
final class InvalidExceptions extends InvalidArgumentException {}
