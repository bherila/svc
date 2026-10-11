#!/usr/bin/env php
<?php

// A child may exit while the runner generates the next mutations. Refresh its
// status before checking the deadline: checking a cached "started" status can
// otherwise mark an already-completed failing test as timed out. Running
// children keep their original deadline. MutationProcessPollingTest covers both.
// Remove this compatibility patch when the dependency polls in that order.
$path = dirname(__DIR__).'/vendor/infection/infection/src/Process/Runner/ParallelProcessRunner.php';

// The mutation runner is a development dependency and absent in production.
if (! is_file($path)) {
    exit(0);
}

$before = <<<'PHP'
            try {
                $process->checkTimeout();
PHP;
$after = <<<'PHP'
            try {
                $process->isRunning();
                $process->checkTimeout();
PHP;
$source = file_get_contents($path);
if ($source !== false && str_contains($source, $after)) {
    exit(0);
}

if ($source === false || substr_count($source, $before) !== 1) {
    fwrite(STDERR, "Mutation process polling changed; review the compatibility patch.\n");
    exit(1);
}

$patched = str_replace($before, $after, $source);
if (file_put_contents($path, $patched) !== strlen($patched)) {
    fwrite(STDERR, "Could not correct mutation process polling.\n");
    exit(1);
}
