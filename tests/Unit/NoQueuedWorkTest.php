<?php

namespace Tests\Unit;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * Production runs no queue worker. `QUEUE_CONNECTION=database` writes a
 * dispatched job to `jobs` and nothing ever reads it, while the suite's
 * `QUEUE_CONNECTION=sync` runs the same job inline, so a queued send passes
 * every test and never happens. That is exactly how "Send to client" left
 * invoice delivery 8 pending on 2026-09-02 (bherila/svc#330). Work that must
 * happen happens in the request, deferred to commit where it is irrevocable.
 */
class NoQueuedWorkTest extends TestCase
{
    public function test_no_application_class_is_queued(): void
    {
        $offenders = [];

        foreach ($this->applicationClasses() as $class) {
            $reflection = new ReflectionClass($class);
            if ($reflection->implementsInterface(ShouldQueue::class)) {
                $offenders[] = $class.' implements ShouldQueue';
            }
            if ($reflection->implementsInterface(ShouldBroadcast::class)) {
                $offenders[] = $class.' implements ShouldBroadcast, which queues';
            }
        }

        $this->assertSame([], $offenders, "Nothing reads the production queue:\n".implode("\n", $offenders));
    }

    public function test_no_application_code_queues_mail_or_notifications(): void
    {
        $offenders = [];

        foreach ($this->applicationFiles() as $file) {
            $source = (string) file_get_contents($file);
            if (preg_match_all('/(?:->|::)(queue|later|laterOn|queueOn)\s*\(/', $source, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as [$call, $offset]) {
                    $offenders[] = $this->relative($file).':'.(substr_count($source, "\n", 0, (int) $offset) + 1).' '.$call;
                }
            }
        }

        $this->assertSame([], $offenders, "Queued mail or notifications are never delivered in production:\n".implode("\n", $offenders));
    }

    /** @return list<class-string> */
    private function applicationClasses(): array
    {
        $classes = [];
        foreach ($this->applicationFiles() as $file) {
            $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($file, strlen($this->appPath()) + 1));
            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        $this->assertNotSame([], $classes, 'No application classes were discovered; the guard would pass vacuously.');

        return $classes;
    }

    /** @return list<string> */
    private function applicationFiles(): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->appPath(), RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private function appPath(): string
    {
        return dirname(__DIR__, 2).'/app';
    }

    private function relative(string $file): string
    {
        return substr($file, strlen(dirname(__DIR__, 2)) + 1);
    }
}
