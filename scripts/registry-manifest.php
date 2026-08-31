#!/usr/bin/env php
<?php

use Illuminate\Contracts\Console\Kernel;
use Tests\Feature\Models\NullSemanticsRegistryTest;

/**
 * Regenerate the null-semantics registry's two pinned manifests.
 *
 *   php scripts/registry-manifest.php           # show the diff; no writes
 *   php scripts/registry-manifest.php --apply   # rewrite the constants
 *
 * `NullSemanticsRegistryTest` ratchets on identity: REGISTERED_BRANCHES names
 * every `table.column => kind:Class::method` the registry may not lose, and
 * PENDING_COLUMNS names every column whose null has no known reader. Both are
 * compared as exact sets, so adding a branch means editing the constant in the
 * same commit - which is the point, and also a guaranteed merge conflict the
 * moment two branches add one at the same time.
 *
 * Hand-resolving a sorted 71-line constant is exactly the friction that gets a
 * guard deleted rather than fixed. With this, resolving is: take both sides,
 * re-run, commit. It also removes the temptation to hand-edit a manifest, which
 * is where a silent weakening would enter.
 *
 * The generator deliberately cannot weaken anything on its own: it derives the
 * manifests from REGISTRY, so running it after deleting a branch produces a
 * manifest that matches the deletion. It resolves conflicts; it does not
 * approve them. Read the diff.
 */
require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$file = __DIR__.'/../tests/Feature/Models/NullSemanticsRegistryTest.php';
$apply = in_array('--apply', $argv, true);

$reflection = new ReflectionClass(NullSemanticsRegistryTest::class);
/** @var array<string, array<string, mixed>> $registry */
$registry = $reflection->getConstant('REGISTRY');

$branches = [];
$pending = [];

foreach ($registry as $table => $columns) {
    foreach ($columns as $column => $entry) {
        if ($entry === 'PENDING-AUDIT') {
            $pending[] = "{$table}.{$column}";

            continue;
        }

        $list = (isset($entry['covered_by']) || isset($entry['reader_in'])) ? [$entry] : $entry;

        foreach ($list as $one) {
            $kind = isset($one['covered_by']) ? 'covered_by' : 'reader_in';
            $class = $one['covered_by'] ?? $one['reader_in'];
            $method = $one['method'] ?? $one['reads'];
            $branches[] = "{$table}.{$column} => {$kind}:{$class}::{$method}";
        }
    }
}

sort($branches);
sort($pending);

$render = static fn (array $lines): string => implode(
    "\n",
    array_map(static fn (string $line): string => "        '".$line."',", $lines),
);

$source = (string) file_get_contents($file);
$updated = $source;

foreach ([
    'REGISTERED_BRANCHES' => $branches,
    'PENDING_COLUMNS' => $pending,
] as $constant => $lines) {
    $pattern = '/(private const '.$constant.' = \[\n).*?(\n    \];)/s';
    $replacement = '${1}'.str_replace('$', '\\$', $render($lines)).'${2}';
    $next = preg_replace($pattern, $replacement, $updated, 1);

    if (! is_string($next)) {
        fwrite(STDERR, "Could not locate {$constant} in the registry test.\n");
        exit(1);
    }

    $updated = $next;
}

if ($updated === $source) {
    fwrite(STDOUT, 'Manifests already match the registry: '.count($branches).' branches, '.count($pending)." pending.\n");
    exit(0);
}

if (! $apply) {
    fwrite(STDOUT, 'Manifests are out of date. Would write '.count($branches).' branches and '.count($pending)." pending.\n");
    fwrite(STDOUT, "Re-run with --apply, then read the diff before committing.\n");
    exit(1);
}

file_put_contents($file, $updated);
fwrite(STDOUT, 'Wrote '.count($branches).' branches and '.count($pending)." pending columns.\n");
