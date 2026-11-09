<?php

/**
 * Per-mutant timing report for the diff-scoped mutation lane.
 *
 * Reads the `--teamcity` stream scripts/run-mutation-diff.sh tees into its
 * diagnostics. That stream is written as each mutant finishes, so the report
 * works on a run the job deadline cut off: it counts the mutants that finished,
 * names the ones still running, and lists the slowest, so a slow run can be
 * attributed to particular mutants rather than guessed at.
 *
 * Usage: php scripts/mutation-timings.php <mutants.teamcity.log> [top-n]
 * Exit:  0 = report written (including for a partial log), 2 = unusable input.
 *
 * Infection's per-mutant duration is its process runtime and is approximate
 * (infection/infection#2900): good for ranking, not for exact budgets.
 */
const MUTATION_TIMINGS_DEFAULT_TOP = 15;

/**
 * @return array<string, string>
 */
function mutation_timings_attributes(string $body): array
{
    preg_match_all("/(\\w+)='((?:[^'|]|\\|.)*)'/s", $body, $matches, PREG_SET_ORDER);
    $attributes = [];
    foreach ($matches as $match) {
        $attributes[$match[1]] = strtr($match[2], ["|'" => "'", '|n' => "\n", '|r' => "\r", '|[' => '[', '|]' => ']', '||' => '|']);
    }

    return $attributes;
}

/**
 * @return array{finished: list<array{name: string, location: string, status: string, ms: int}>, running: list<array{name: string, location: string}>}
 */
function mutation_timings_parse(string $log): array
{
    $mutants = [];
    // testStdOut names its mutant but carries no nodeId.
    $nodesByName = [];
    foreach (preg_split('/\R/', $log) ?: [] as $line) {
        if (preg_match('/^##teamcity\[(\w+)\s(.*)\]$/s', $line, $match) !== 1) {
            continue;
        }
        [$message, $attributes] = [$match[1], mutation_timings_attributes($match[2])];
        $node = $attributes['nodeId'] ?? $nodesByName[$attributes['name'] ?? ''] ?? null;
        if ($node === null) {
            continue;
        }
        if ($message === 'testStarted') {
            $mutants[$node] = ['name' => $attributes['name'] ?? $node, 'location' => $attributes['locationHint'] ?? '', 'status' => null, 'ms' => null];
            $nodesByName[$mutants[$node]['name']] = $node;

            continue;
        }
        if (! isset($mutants[$node])) {
            continue;
        }
        if ($message === 'testStdOut' && preg_match('/Mutation result: (.+)/', $attributes['out'] ?? '', $result) === 1) {
            $mutants[$node]['status'] = trim($result[1]);
        } elseif ($message === 'testFailed') {
            $mutants[$node]['status'] = preg_match('/Mutation result: (.+)/', $attributes['message'] ?? '', $result) === 1 ? trim($result[1]) : 'escaped';
        } elseif ($message === 'testIgnored') {
            $mutants[$node]['status'] = preg_match('/Mutation result: (.+)/', $attributes['message'] ?? '', $result) === 1 ? trim($result[1]) : 'ignored';
        }
        if (in_array($message, ['testFinished', 'testFailed', 'testIgnored'], true)) {
            $mutants[$node]['status'] ??= 'finished';
            $mutants[$node]['ms'] = (int) ($attributes['duration'] ?? 0);
        }
    }

    $finished = [];
    $running = [];
    foreach ($mutants as $mutant) {
        if ($mutant['ms'] === null) {
            $running[] = ['name' => $mutant['name'], 'location' => $mutant['location']];
        } else {
            $finished[] = ['name' => $mutant['name'], 'location' => $mutant['location'], 'status' => (string) $mutant['status'], 'ms' => $mutant['ms']];
        }
    }

    return ['finished' => $finished, 'running' => $running];
}

/**
 * `infection://path::start-end` byte offsets, as `path:line` when the file is readable.
 */
function mutation_timings_location(string $hint): string
{
    if (preg_match('#^infection://(.+)::(\d+)-\d+$#', $hint, $match) !== 1) {
        return $hint;
    }
    $path = $match[1];
    $source = is_file($path) ? file_get_contents($path) : false;
    $relative = str_starts_with($path, getcwd().'/') ? substr($path, strlen(getcwd()) + 1) : $path;
    if (! is_string($source)) {
        return $relative.'@'.$match[2];
    }

    return $relative.':'.(substr_count(substr($source, 0, (int) $match[2]), "\n") + 1);
}

function mutation_timings_report(string $log, int $top = MUTATION_TIMINGS_DEFAULT_TOP): string
{
    $parsed = mutation_timings_parse($log);
    $finished = $parsed['finished'];
    $statuses = array_count_values(array_column($finished, 'status'));
    ksort($statuses);
    $total = array_sum(array_column($finished, 'ms'));

    $markdown = "### Mutation timings\n\n";
    $markdown .= sprintf("%d mutants finished, %d still running when the log ends; %.1fs of mutant process time in total.\n", count($finished), count($parsed['running']), $total / 1000);
    if ($statuses !== []) {
        $markdown .= "\n".implode(', ', array_map(static fn (string $status, int $count): string => "{$status}: {$count}", array_keys($statuses), $statuses))."\n";
    }
    if ($finished !== []) {
        usort($finished, static fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);
        $markdown .= "\n| Seconds | Outcome | Mutator (id) | Location |\n| ---: | --- | --- | --- |\n";
        foreach (array_slice($finished, 0, $top) as $mutant) {
            $markdown .= sprintf("| %.1f | %s | %s | `%s` |\n", $mutant['ms'] / 1000, $mutant['status'], $mutant['name'], mutation_timings_location($mutant['location']));
        }
    }
    if ($parsed['running'] !== []) {
        $markdown .= "\nStill running when the log ends:\n\n";
        foreach (array_slice($parsed['running'], 0, $top) as $mutant) {
            $markdown .= sprintf("- %s `%s`\n", $mutant['name'], mutation_timings_location($mutant['location']));
        }
    }

    return $markdown."\nDurations are Infection's process runtimes and are approximate (infection/infection#2900).\n";
}

function mutation_timings_main(array $argv): int
{
    $path = $argv[1] ?? null;
    $log = is_string($path) && is_file($path) ? file_get_contents($path) : false;
    if (! is_string($log)) {
        fwrite(STDERR, "usage: mutation-timings.php <mutants.teamcity.log> [top-n] (log not readable)\n");

        return 2;
    }

    $report = mutation_timings_report($log, isset($argv[2]) ? max(1, (int) $argv[2]) : MUTATION_TIMINGS_DEFAULT_TOP);
    echo $report;
    $summary = getenv('GITHUB_STEP_SUMMARY');
    if (is_string($summary) && $summary !== '') {
        file_put_contents($summary, "\n".$report, FILE_APPEND);
    }

    return 0;
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(mutation_timings_main($argv));
}
