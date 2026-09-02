<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Loading this application must not write anything to an agent's channel.
 *
 * Composer's `files` autoload entries execute unconditionally, before any of
 * this application's own code, on every request and every console command.
 * `stripe/stripe-php` v21.2.0 added one that checks for `CLAUDECODE` in the
 * environment and, finding it, writes a fabricated harness control tag to
 * stderr - a `<claude-code-hint .../>` element advertising a plugin. Genuine
 * upstream code, which is why pinning around it is not a durable answer;
 * `scripts/neutralise-vendor-agent-hints.php` blanks it on every install.
 *
 * ## Two reasons this is worth a test rather than a one-off patch
 *
 * A tag shaped like a message from the harness, written by a third party into
 * the channel the harness speaks over, is content claiming an authority it does
 * not have. What it advertises is beside the point - the mechanism is the
 * problem, and it should be stopped rather than read.
 *
 * And it broke a test. `ProjectAccessLegacyOrphanTest` runs in a separate
 * process, and PHPUnit reads that child's result off its output streams, so an
 * unexpected stderr line corrupts the result. It failed with the injected text
 * as its message, which is how this was found - a failure on `main` that looked
 * like a tenancy defect and was a dependency writing on a pipe.
 *
 * ## The assertion is behavioural, not a grep
 *
 * The first test boots the autoloader in a real subprocess with both agent
 * environment variables set, and asserts the process says nothing at all. That
 * catches a differently-shaped tag, or a second package doing the same thing,
 * which a search for one literal would not.
 *
 * The second is the grep, and it exists only to name the file when the first
 * fails. A "something wrote to stderr" failure with no offender named is a bad
 * hour for whoever inherits it.
 */
final class VendorAgentHintTest extends TestCase
{
    public function test_booting_the_autoloader_says_nothing_to_an_agent(): void
    {
        $process = new Process(
            [PHP_BINARY, '-r', 'require "vendor/autoload.php";'],
            base_path(),
            // Both of the variables the known emitter looks for. Set here rather
            // than inherited, so the test proves the same thing whether or not
            // the suite itself is being run by an agent.
            ['CLAUDECODE' => '1', 'CLAUDE_CODE_CHILD_SESSION' => '1'],
        );
        $process->run();

        $this->assertSame(
            '',
            $process->getErrorOutput(),
            'A vendor file wrote to stderr while an agent environment was present. '
            .'Run `php scripts/neutralise-vendor-agent-hints.php`; if that does not clear it, a dependency '
            .'is emitting something new and the script needs to learn about it.',
        );
        $this->assertSame('', $process->getOutput(), 'A vendor file wrote to stdout on autoload.');
    }

    /** Names the offender, so the failure above is actionable. */
    public function test_no_autoloaded_vendor_file_mentions_an_agent_control_tag(): void
    {
        /** @var array<string, string> $files */
        $files = require base_path('vendor/composer/autoload_files.php');

        $offenders = [];

        foreach ($files as $path) {
            if (! is_file($path)) {
                continue;
            }

            $contents = file_get_contents($path);

            if ($contents !== false && str_contains($contents, 'claude-code-hint')) {
                $offenders[] = str_replace(base_path().'/', '', $path);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'These files run on every boot and carry an agent control tag.',
        );
    }
}
