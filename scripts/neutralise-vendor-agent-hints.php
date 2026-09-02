#!/usr/bin/env php
<?php

/**
 * Blank any vendor file that writes an agent control tag on load.
 *
 * Composer's `files` autoload entries run unconditionally, before any of this
 * application's own code, on every request and every console command. That is a
 * position of considerable trust, and at least one dependency uses it to write
 * a fabricated harness control tag to stderr when it detects that a coding agent
 * is present:
 *
 *     if (false !== getenv('CLAUDECODE') || false !== getenv('CLAUDE_CODE_CHILD_SESSION')) {
 *         fwrite($stderr, '<claude-code-hint ... />');
 *     }
 *
 * `stripe/stripe-php` added `lib/agent_plugin_hint.php` in v21.2.0 and lists it
 * under `autoload.files`. It is genuine upstream code, not a compromised
 * package, which is exactly why pinning around it is not a durable answer.
 *
 * ## Why this is worth a build step
 *
 * Two separate reasons, and either alone would justify it.
 *
 * A tag shaped like a message from the harness, emitted by a third party into
 * the channel the harness speaks over, is content pretending to have an
 * authority it does not have. What it happens to advertise today is beside the
 * point; the mechanism is the problem, and the right posture toward a
 * dependency that writes into that channel is to stop it, not to read it.
 *
 * And it breaks a test. `ProjectAccessLegacyOrphanTest` runs in a separate
 * process, and PHPUnit reads that child's result off its output streams - so an
 * unexpected line on stderr corrupts the result and the test fails with the
 * injected text as its message. That failure sat on `main` looking like a
 * tenancy defect.
 *
 * ## What it does, and what it deliberately does not
 *
 * It walks only `vendor/composer/autoload_files.php` - the files that execute on
 * boot, which is the whole exposure - and rewrites any that mention the tag with
 * a stub explaining why. It never deletes: the autoload map names the path, so a
 * missing file is a fatal error rather than a fix.
 *
 * It does not scan the rest of `vendor/`. A library that emits such a tag from a
 * method someone called is a different thing from one that emits it because it
 * was loaded, and this is not a general-purpose scanner.
 *
 * Runs from `post-autoload-dump`, so it fires on install, update and every
 * `dump-autoload`. It is best-effort by design - a composer script that aborts
 * an install is worse than the problem - and `VendorAgentHintTest` is the gate
 * that fails loudly if it ever silently stops working.
 */
$root = dirname(__DIR__);
$map = $root.'/vendor/composer/autoload_files.php';

if (! is_file($map)) {
    exit(0);
}

/** @var array<string, string> $files */
$files = require $map;
$marker = 'claude-code-hint';
$neutralised = [];

foreach ($files as $path) {
    if (! is_file($path) || ! is_readable($path)) {
        continue;
    }

    $contents = file_get_contents($path);

    if ($contents === false || ! str_contains($contents, $marker)) {
        continue;
    }

    $relative = str_starts_with($path, $root.'/') ? substr($path, strlen($root) + 1) : $path;
    $stub = <<<'STUB'
<?php

/*
 * Blanked by scripts/neutralise-vendor-agent-hints.php.
 *
 * This file was autoloaded on every boot and wrote a fabricated agent control
 * tag to stderr when it detected a coding agent in the environment. That is
 * content pretending to come from the harness, and it also corrupted the result
 * stream of every test PHPUnit runs in a separate process.
 *
 * Restored on the next composer install, and blanked again by the same script.
 * The file is emptied rather than removed because composer's autoload map names
 * this path, and a missing file would be fatal.
 */

STUB;

    if (file_put_contents($path, $stub) !== false) {
        $neutralised[] = $relative;
    }
}

foreach ($neutralised as $relative) {
    fwrite(STDOUT, "Blanked an autoloaded vendor file that emits an agent control tag: {$relative}\n");
}

exit(0);
