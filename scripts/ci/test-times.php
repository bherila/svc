<?php

/**
 * Rebuild tests/Fixtures/ci-test-times.json, the per-file times that balance the CI shards.
 *
 *   php scripts/ci/test-times.php <junit.xml>... > tests/Fixtures/ci-test-times.json
 *
 * Pass the JUnit logs of every shard of one MariaDB run (the `junit-mariadb-*` artifacts of the
 * tests workflow): `gh run download <run> -p 'junit-mariadb-*' -D <dir>`. Each test file gets
 * the sum of its test times, in seconds to one decimal, keyed by repository-relative path.
 */

declare(strict_types=1);

$logs = array_slice($argv, 1);
if ($logs === []) {
    fwrite(STDERR, "usage: test-times.php <junit.xml>...\n");
    exit(1);
}

$times = [];
foreach ($logs as $log) {
    $document = new DOMDocument;
    if (! @$document->load($log)) {
        fwrite(STDERR, "test-times: unreadable JUnit log {$log}\n");
        exit(1);
    }
    foreach ($document->getElementsByTagName('testcase') as $case) {
        $file = $case->getAttribute('file');
        $at = strpos($file, '/tests/');
        if ($at === false) {
            fwrite(STDERR, "test-times: {$log} has a test case outside tests/: {$file}\n");
            exit(1);
        }
        $relative = substr($file, $at + 1);
        $times[$relative] = ($times[$relative] ?? 0.0) + (float) $case->getAttribute('time');
    }
}

if ($times === []) {
    fwrite(STDERR, "test-times: the logs hold no test cases\n");
    exit(1);
}

ksort($times, SORT_STRING);
echo json_encode(array_map(fn (float $seconds): float => round($seconds, 1), $times), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), "\n";
