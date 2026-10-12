<?php

/**
 * Measurement-integrity gate for the diff-scoped mutation lane (#443).
 *
 * Infection's own threshold only judges mutants it managed to classify. A run
 * in which every mutant timed out or was skipped classifies none of them, so it
 * can report a passing (or ignorable) score while measuring nothing. This gate
 * fails when more than half of the generated mutants are unresolved, and
 * writes the counts to the GitHub job summary when one is available.
 *
 * Usage: php scripts/mutation-gate.php <summary.json> [max-unresolved-ratio]
 * Exit:  0 = measured, 1 = unmeasured, 2 = unusable input.
 */
const DEFAULT_MAX_UNRESOLVED_RATIO = 0.5;

/**
 * @param  array<string, mixed>  $stats  The `stats` object of Infection's summary JSON.
 * @return array{ok: bool, total: int, killed: int, escaped: int, timedOut: int, skipped: int, unresolved: int, message: string}
 */
function mutation_gate_evaluate(array $stats, float $maxUnresolvedRatio = DEFAULT_MAX_UNRESOLVED_RATIO): array
{
    $count = static fn (string $key): int => (int) ($stats[$key] ?? 0);

    $total = $count('totalMutantsCount');
    $timedOut = $count('timeOutCount');
    $skipped = $count('skippedCount');
    $unresolved = $timedOut + $skipped;
    $ok = $total === 0 || $unresolved <= $total * $maxUnresolvedRatio;

    return [
        'ok' => $ok,
        'total' => $total,
        'killed' => $count('killedCount'),
        'escaped' => $count('escapedCount'),
        'timedOut' => $timedOut,
        'skipped' => $skipped,
        'unresolved' => $unresolved,
        'message' => $ok
            ? ''
            : sprintf(
                '%d of %d mutants timed out or were skipped (limit %d%%); the tests were not measured, so this result is not evidence either way. Re-run, or reduce the per-mutant cost of the covering tests.',
                $unresolved,
                $total,
                (int) round($maxUnresolvedRatio * 100),
            ),
    ];
}

function mutation_gate_main(array $argv): int
{
    $path = $argv[1] ?? null;
    $ratio = isset($argv[2]) ? (float) $argv[2] : DEFAULT_MAX_UNRESOLVED_RATIO;

    if ($path === null) {
        fwrite(STDERR, "usage: mutation-gate.php <summary.json> [max-unresolved-ratio]\n");

        return 2;
    }

    $json = is_file($path) ? file_get_contents($path) : false;
    $data = is_string($json) ? json_decode($json, true) : null;
    $stats = is_array($data) ? ($data['stats'] ?? null) : null;

    if (! is_array($stats)) {
        $message = "Infection produced no readable summary at {$path}; nothing was measured.";
        fwrite(STDERR, $message."\n");
        mutation_gate_publish("### Mutation gate\n\n**Failed:** {$message}\n");
        if (getenv('GITHUB_ACTIONS') === 'true') {
            echo "::error title=Mutation result unavailable::{$message}\n";
        }

        return 1;
    }

    $result = mutation_gate_evaluate($stats, $ratio);
    $line = sprintf(
        'Mutants: %d total, %d killed, %d escaped, %d timed out, %d skipped.',
        $result['total'],
        $result['killed'],
        $result['escaped'],
        $result['timedOut'],
        $result['skipped'],
    );
    echo $line."\n";

    $markdown = "### Mutation gate\n\n".$line."\n";
    if (! $result['ok']) {
        $markdown .= "\n**Failed:** ".$result['message']."\n";
        fwrite(STDERR, $result['message']."\n");
        if (getenv('GITHUB_ACTIONS') === 'true') {
            echo "::error title=Mutation lane measured nothing::{$result['message']}\n";
        }
    }
    mutation_gate_publish($markdown);

    return $result['ok'] ? 0 : 1;
}

function mutation_gate_publish(string $markdown): void
{
    $file = getenv('GITHUB_STEP_SUMMARY');
    if (is_string($file) && $file !== '') {
        file_put_contents($file, "\n".$markdown, FILE_APPEND);
    }
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(mutation_gate_main($argv));
}
