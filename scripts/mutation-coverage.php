<?php

/**
 * What the diff-scoped mutation lane did not measure (#661).
 *
 * The lane's baseline is the Unit suite alone (infection.diff.json5), so a
 * changed line that only a Feature test reaches produces no mutant: a service
 * change with no unit coverage passed as "0 mutants" - indistinguishable from
 * a change the lane measured and found nothing in. This reads the baseline's
 * coverage map and names every changed method no Unit test executes, so the
 * result says "not measured" for them instead of reading as success.
 *
 * PHPUnit's coverage XML lists covered lines only, so the unit is the method:
 * a changed line inside a method none of whose lines a Unit test runs is not
 * measured. A changed file the Unit suite never loaded is not measured whole.
 * The lane is advisory (docs/client-management/mutation-testing.md): this
 * reports, it does not fail.
 *
 * Usage: php scripts/mutation-coverage.php <base> <coverage-xml-dir>
 * Exit:  0 = reported, 2 = unusable input.
 */

/**
 * The added and changed lines of each file in a `git diff -U0`.
 *
 * @return array<string, list<int>> path => line numbers in the new file
 */
function mutation_coverage_changed_lines(string $diff): array
{
    $changed = [];
    $path = null;
    foreach (preg_split('/\R/', $diff) ?: [] as $line) {
        if (str_starts_with($line, '+++ ')) {
            $path = $line === '+++ /dev/null' ? null : preg_replace('#^\+\+\+ (?:b/)?#', '', $line);

            continue;
        }
        if ($path !== null && preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/', $line, $hunk) === 1) {
            $start = (int) $hunk[1];
            $count = isset($hunk[2]) ? (int) $hunk[2] : 1;
            for ($number = $start; $number < $start + $count; $number++) {
                $changed[$path][] = $number;
            }
        }
    }

    return $changed;
}

/**
 * The changed methods no Unit test executes, and the changed files it never loaded.
 *
 * @param  array<string, list<int>>  $changed  from mutation_coverage_changed_lines()
 * @return list<string> one line each, `path: Class::method` or `path: not loaded`
 */
function mutation_coverage_unmeasured(array $changed, string $coverageDirectory): array
{
    $unmeasured = [];
    foreach ($changed as $path => $lines) {
        if (! str_starts_with($path, 'app/') || ! str_ends_with($path, '.php') || $lines === []) {
            continue;
        }
        $xml = rtrim($coverageDirectory, '/').'/'.substr($path, strlen('app/')).'.xml';
        if (! is_file($xml)) {
            $unmeasured[] = $path.': not loaded by the Unit suite';

            continue;
        }
        $document = simplexml_load_file($xml);
        if ($document === false) {
            $unmeasured[] = $path.': coverage unreadable';

            continue;
        }
        $document->registerXPathNamespace('c', 'https://schema.phpunit.de/coverage/1.0');
        foreach ($document->xpath('//c:class|//c:trait') ?: [] as $type) {
            // Children read through the namespace carry their attributes
            // outside it: read them through attributes().
            foreach ($type->children('https://schema.phpunit.de/coverage/1.0')->method as $method) {
                $attributes = $method->attributes();
                $start = (int) $attributes['start'];
                $end = (int) $attributes['end'];
                if ((int) $attributes['executed'] > 0 || (int) $attributes['executable'] === 0) {
                    continue;
                }
                foreach ($lines as $line) {
                    if ($line >= $start && $line <= $end) {
                        $unmeasured[] = $path.': '.$type['name'].'::'.$attributes['name'];

                        break;
                    }
                }
            }
        }
    }

    return array_values(array_unique($unmeasured));
}

/** @param list<string> $argv */
function mutation_coverage_main(array $argv): int
{
    $base = $argv[1] ?? null;
    $directory = $argv[2] ?? null;
    if ($base === null || $directory === null) {
        fwrite(STDERR, "usage: mutation-coverage.php <base> <coverage-xml-dir>\n");

        return 2;
    }
    if (! is_dir($directory)) {
        fwrite(STDERR, "No Unit coverage at {$directory}; what was measured cannot be told.\n");

        return 2;
    }

    $diff = shell_exec('git -c diff.renames=false diff --no-ext-diff --no-textconv -U0 --diff-filter=AM '
        .escapeshellarg($base).' HEAD -- '.escapeshellarg(':(glob)app/**/*.php'));
    if (! is_string($diff)) {
        fwrite(STDERR, "Could not read the diff against {$base}.\n");

        return 2;
    }

    $unmeasured = mutation_coverage_unmeasured(mutation_coverage_changed_lines($diff), $directory);
    if ($unmeasured === []) {
        echo "Every changed method is executed by a Unit test.\n";

        return 0;
    }

    $count = count($unmeasured);
    $headline = "Not measured: {$count} changed method(s) or file(s) under app/ are executed by no Unit test, so the lane produced no mutant for them and its result says nothing about them.";
    echo $headline."\n".implode("\n", array_map(static fn (string $site): string => '- '.$site, $unmeasured))."\n";
    $file = getenv('GITHUB_STEP_SUMMARY');
    if (is_string($file) && $file !== '') {
        file_put_contents($file, "\n### Not measured\n\n".$headline." Cover them with a unit test, or verify the change by reverting it.\n\n"
            .implode("\n", array_map(static fn (string $site): string => '- `'.$site.'`', $unmeasured))."\n", FILE_APPEND);
    }
    if (getenv('GITHUB_ACTIONS') === 'true') {
        echo "::warning title=Mutation lane: not measured::{$headline}\n";
    }

    return 0;
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(mutation_coverage_main($argv));
}
