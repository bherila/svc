<?php

/**
 * Split the PHPUnit suite into N deterministic shards, one per CI job and database lane.
 *
 *   php scripts/ci/phpunit-shard.php <shard> <total> <output-config> [--listing=<file>]
 *   php scripts/ci/phpunit-shard.php --plan <total> [--listing=<file>]
 *
 * The file list comes from PHPUnit itself (`--list-tests-xml` under phpunit.xml), so a shard
 * can never invent a test file or miss one PHPUnit would run. Every listed file is assigned to exactly
 * one shard; the script verifies that partition before it writes anything and exits non-zero
 * if it does not hold.
 *
 * Shards are balanced greedily by test count (data-provider rows included), heaviest file
 * first, ties broken by path, so the assignment depends only on the tree. The output is a copy
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
 * @param  array<string, int>  $counts
 * @return list<list<string>>
 */
function shard_partition(array $counts, int $total): array
{
    $files = array_keys($counts);
    usort($files, fn (string $a, string $b): int => [$counts[$b], $a] <=> [$counts[$a], $b]);

    $shards = array_fill(0, $total, []);
    $loads = array_fill(0, $total, 0);

    foreach ($files as $file) {
        $lightest = array_keys($loads, min($loads), true)[0];
        $shards[$lightest][] = $file;
        $loads[$lightest] += $counts[$file];
    }

    foreach ($shards as $index => $shard) {
        sort($shard, SORT_STRING);
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
foreach ($arguments as $index => $argument) {
    if (str_starts_with($argument, '--listing=')) {
        $listingFile = substr($argument, strlen('--listing='));
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
    $plan = [];
    foreach (shard_partition($counts, $total) as $shard) {
        $plan[] = ['files' => $shard, 'tests' => array_sum(array_map(fn (string $file): int => $counts[$file], $shard))];
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
$files = shard_partition($counts, $total)[$shard - 1];

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
