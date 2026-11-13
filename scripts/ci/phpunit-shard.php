<?php

/**
 * Split the PHPUnit suite into N deterministic shards, one per CI job and database lane.
 *
 *   php scripts/ci/phpunit-shard.php <shard> <total> <output-config> [--listing=<file>] [--times=<file>]
 *   php scripts/ci/phpunit-shard.php --plan <total> [--listing=<file>] [--times=<file>]
 *
 * The file list comes from PHPUnit itself (`--list-tests-xml` under phpunit.xml), so a shard
 * can never invent a test file or miss one PHPUnit would run. Every listed file is assigned to exactly
 * one shard; the script verifies that partition before it writes anything and exits non-zero
 * if it does not hold.
 *
 * Shards are balanced greedily on each file's measured time, heaviest file first, ties broken
 * by path, so the assignment depends only on the tree and the committed times. The times are
 * per-file seconds from the MariaDB lane (tests/Fixtures/ci-test-times.json, rebuilt by
 * scripts/ci/test-times.php from the lane's uploaded JUnit logs). A file with no recorded time
 * weighs its test count (data-provider rows included) times the recorded seconds per test, and
 * with no times at all the shards balance on test count alone. Stale times only unbalance the
 * shards; they can never drop or duplicate a file. The output is a copy
 * of phpunit.xml whose test suites are replaced by an explicit file list; everything else --
 * environment and its override semantics -- is carried over unchanged. Paths are made absolute
 * so the generated configuration can safely live in the runner temporary directory.
 *
 * `--plan` prints every shard as JSON instead, for the contract test.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function shard_fail(string $message): never
{
    fwrite(STDERR, "phpunit-shard: {$message}\n");
    exit(1);
}

/**
 * Test count per file, keyed by repository-relative path.
 *
 * @return array<string, int>
 */
function shard_listing(string $root, ?string $listingFile): array
{
    if ($listingFile === null) {
        $listingFile = tempnam(sys_get_temp_dir(), 'phpunit-listing-');
        if ($listingFile === false) {
            shard_fail('could not create a temporary listing file');
        }

        $command = [PHP_BINARY, '-d', 'memory_limit=1G', $root.'/vendor/bin/phpunit', '--configuration', $root.'/phpunit.xml', '--list-tests-xml', $listingFile];
        $process = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => STDERR], $pipes, $root);
        if (! is_resource($process) || proc_close($process) !== 0) {
            unlink($listingFile);
            shard_fail('PHPUnit could not list the test suite');
        }
        $temporaryListing = true;
    }

    $document = new DOMDocument;
    if (! @$document->load($listingFile)) {
        shard_fail("unreadable test listing {$listingFile}");
    }

    if ($temporaryListing ?? false) {
        unlink($listingFile);
    }

    $counts = [];
    foreach ($document->getElementsByTagName('testClass') as $class) {
        $file = $class->getAttribute('file');
        $relative = str_starts_with($file, $root.'/') ? substr($file, strlen($root) + 1) : $file;
        $tests = 0;
        foreach ($class->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $tests++;
            }
        }
        $counts[$relative] = ($counts[$relative] ?? 0) + max(1, $tests);
    }

    if ($counts === []) {
        shard_fail('PHPUnit listed no test files');
    }

    return $counts;
}

/**
 * Each file's weight in milliseconds of measured time, or its test count when nothing is measured.
 *
 * @param  array<string, int>  $counts
 * @return array<string, int>
 */
function shard_weights(array $counts, string $timesFile): array
{
    $times = [];
    if (is_file($timesFile)) {
        $decoded = json_decode((string) file_get_contents($timesFile), true);
        if (! is_array($decoded)) {
            shard_fail("unreadable test times {$timesFile}");
        }
        foreach ($decoded as $file => $seconds) {
            if (! is_string($file) || ! is_int($seconds) && ! is_float($seconds) || $seconds < 0) {
                shard_fail("test times {$timesFile} must map each file to its seconds");
            }
            $times[$file] = (int) round($seconds * 1000);
        }
    }

    $known = array_intersect_key($times, $counts);
    if ($known === []) {
        return $counts;
    }
    $knownTests = array_sum(array_intersect_key($counts, $known));
    $perTest = max(1, intdiv(array_sum($known), max(1, $knownTests)));

    $weights = [];
    foreach ($counts as $file => $tests) {
        $weights[$file] = max(1, $known[$file] ?? $tests * $perTest);
    }

    return $weights;
}

/**
 * @param  array<string, int>  $weights  path => weight
 * @return list<list<string>>
 */
function shard_partition(array $weights, int $total): array
{
    $files = array_keys($weights);
    usort($files, fn (string $a, string $b): int => [$weights[$b], $a] <=> [$weights[$a], $b]);

    $shards = array_fill(0, $total, []);
    $loads = array_fill(0, $total, 0);

    foreach ($files as $file) {
        $lightest = array_keys($loads, min($loads), true)[0];
        $shards[$lightest][] = $file;
        $loads[$lightest] += $weights[$file];
    }

    // Heaviest first: ParaTest hands files to its workers in the order the configuration lists
    // them, so the longest file starts at once while the other workers share the rest, rather
    // than starting last and running on alone.
    foreach ($shards as $index => $shard) {
        usort($shard, fn (string $a, string $b): int => [$weights[$b], $a] <=> [$weights[$a], $b]);
        $shards[$index] = $shard;
    }

    // The guard: every listed file in exactly one shard, and no shard empty.
    $assigned = array_merge(...$shards);
    if (count($assigned) !== count(array_unique($assigned)) || array_diff($files, $assigned) !== [] || array_diff($assigned, $files) !== []) {
        shard_fail('the shards are not an exact partition of the test files');
    }
    foreach ($shards as $index => $shard) {
        if ($shard === []) {
            shard_fail('shard '.($index + 1)." of {$total} is empty");
        }
    }

    return $shards;
}

$arguments = array_slice($argv, 1);
$listingFile = null;
$timesFile = $root.'/tests/Fixtures/ci-test-times.json';
foreach ($arguments as $index => $argument) {
    if (str_starts_with($argument, '--listing=')) {
        $listingFile = substr($argument, strlen('--listing='));
        unset($arguments[$index]);
    }
    if (str_starts_with($argument, '--times=')) {
        $timesFile = substr($argument, strlen('--times='));
        unset($arguments[$index]);
    }
}
$arguments = array_values($arguments);

if (($arguments[0] ?? null) === '--plan') {
    $total = (int) ($arguments[1] ?? 0);
    if (count($arguments) !== 2 || ! ctype_digit($arguments[1]) || $total < 1) {
        shard_fail('usage: --plan <total>');
    }
    $counts = shard_listing($root, $listingFile);
    $weights = shard_weights($counts, $timesFile);
    $plan = [];
    foreach (shard_partition($weights, $total) as $shard) {
        $plan[] = [
            'files' => $shard,
            'tests' => array_sum(array_map(fn (string $file): int => $counts[$file], $shard)),
            'weight' => array_sum(array_map(fn (string $file): int => $weights[$file], $shard)),
        ];
    }
    echo json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

if (count($arguments) !== 3) {
    shard_fail('usage: <shard> <total> <output-config>');
}

[$shard, $total, $output] = [(int) $arguments[0], (int) $arguments[1], $arguments[2]];
if (! ctype_digit($arguments[0]) || ! ctype_digit($arguments[1]) || $total < 1 || $shard < 1 || $shard > $total) {
    shard_fail("invalid shard {$arguments[0]} of {$arguments[1]}");
}

$counts = shard_listing($root, $listingFile);
$files = shard_partition(shard_weights($counts, $timesFile), $total)[$shard - 1];

$config = new DOMDocument;
$config->preserveWhiteSpace = true;
if (! $config->load($root.'/phpunit.xml')) {
    shard_fail('unreadable phpunit.xml');
}

$suites = $config->getElementsByTagName('testsuites')->item(0);
if (! $suites instanceof DOMElement) {
    shard_fail('phpunit.xml has no <testsuites>');
}
while ($suites->firstChild !== null) {
    $suites->removeChild($suites->firstChild);
}
$suite = $config->createElement('testsuite');
$suite->setAttribute('name', "Shard {$shard} of {$total}");
foreach ($files as $file) {
    $suite->appendChild($config->createTextNode("\n            "));
    $element = $config->createElement('file');
    $element->textContent = $root.'/'.$file;
    $suite->appendChild($element);
}
$suite->appendChild($config->createTextNode("\n        "));
$suites->appendChild($config->createTextNode("\n        "));
$suites->appendChild($suite);
$suites->appendChild($config->createTextNode("\n    "));

// PHPUnit resolves these paths relative to the generated configuration, not the working directory.
$phpunit = $config->documentElement;
foreach (['bootstrap', 'cacheDirectory'] as $attribute) {
    if ($phpunit->hasAttribute($attribute)) {
        $path = $phpunit->getAttribute($attribute);
        if (! str_starts_with($path, '/')) {
            $phpunit->setAttribute($attribute, $root.'/'.$path);
        }
    }
}
$schemaNamespace = 'http://www.w3.org/2001/XMLSchema-instance';
$schema = $phpunit->getAttributeNS($schemaNamespace, 'noNamespaceSchemaLocation');
if ($schema !== '' && ! str_starts_with($schema, '/') && ! str_contains($schema, '://')) {
    $phpunit->setAttributeNS($schemaNamespace, 'xsi:noNamespaceSchemaLocation', $root.'/'.$schema);
}
$xpath = new DOMXPath($config);
foreach ($xpath->query('/phpunit/source//directory | /phpunit/source//file') as $pathNode) {
    if (! str_starts_with($pathNode->textContent, '/')) {
        $pathNode->textContent = $root.'/'.$pathNode->textContent;
    }
}

if ($config->save($output) === false) {
    shard_fail("could not write {$output}");
}

$tests = array_sum(array_map(fn (string $file): int => $counts[$file], $files));
printf(
    "Shard %d of %d: %d of %d test files, %d of %d tests\n",
    $shard,
    $total,
    count($files),
    count($counts),
    $tests,
    array_sum($counts),
);
