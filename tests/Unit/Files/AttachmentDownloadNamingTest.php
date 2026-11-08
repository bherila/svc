<?php

namespace Tests\Unit\Files;

use App\Services\Files\AttachmentStorageService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

#[CoversClass(AttachmentStorageService::class)]
final class AttachmentDownloadNamingTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function filenames(): array
    {
        return [
            'plain' => ['report.pdf', 'report.pdf'],
            'unicode kept' => ["r\u{00E9}sum\u{00E9}.pdf", "r\u{00E9}sum\u{00E9}.pdf"],
            'unix traversal' => ['../../etc/passwd', 'passwd'],
            'windows path' => ['C:\\temp\\report.pdf', 'report.pdf'],
            'nul byte' => ["a\0b.txt", 'b.txt'],
            'line break' => ["line\nbreak.txt", 'line break.txt'],
            'carriage return run' => ["a\r\n\r\nb.txt", 'a b.txt'],
            'tab and bell' => ["ta\tb\x07.txt", 'tab.txt'],
            'delete char' => ["a\x7Fb.txt", 'ab.txt'],
            'right-to-left override' => ["evil\u{202E}fdp.exe", 'evilfdp.exe'],
            'left-to-right mark' => ["a\u{200E}b.txt", 'ab.txt'],
            'right-to-left mark' => ["a\u{200F}b.txt", 'ab.txt'],
            'embedding start' => ["a\u{202A}b.txt", 'ab.txt'],
            'isolate start' => ["a\u{2066}b.txt", 'ab.txt'],
            'isolate end' => ["a\u{2069}b.txt", 'ab.txt'],
            'zero width space is not a control' => ["a\u{200B}b.txt", "a\u{200B}b.txt"],
            'surrounding space' => ['  spaced.txt  ', 'spaced.txt'],
            'dot' => ['.', 'download'],
            'dot dot' => ['..', 'download'],
            'empty' => ['', 'download'],
            'blank' => ['   ', 'download'],
            'only controls' => ["\x01\x02", 'download'],
            'trailing slash' => ['folder/', 'folder'],
        ];
    }

    #[DataProvider('filenames')]
    public function test_the_served_filename_is_one_clean_segment(string $stored, string $expected): void
    {
        $this->assertSame($expected, $this->invokePrivate('downloadFilename', $stored));
    }

    public function test_the_served_filename_is_capped_at_255_characters_not_bytes(): void
    {
        $this->assertSame(str_repeat('a', 255), $this->invokePrivate('downloadFilename', str_repeat('a', 400)));
        $this->assertSame(str_repeat('a', 255), $this->invokePrivate('downloadFilename', str_repeat('a', 255)));
        $this->assertSame(str_repeat("\u{00E9}", 255), $this->invokePrivate('downloadFilename', str_repeat("\u{00E9}", 300)));
        $this->assertSame(str_repeat('a', 254), $this->invokePrivate('downloadFilename', str_repeat('a', 254)));
    }

    /** @return array<string, array{string, string}> */
    public static function mediaTypes(): array
    {
        return [
            'html' => ['text/html', 'application/octet-stream'],
            'html with charset' => ['text/html; charset=utf-8', 'application/octet-stream'],
            'html upper case' => ['TEXT/HTML', 'application/octet-stream'],
            'html padded' => [' text/html ', 'application/octet-stream'],
            'xhtml' => ['application/xhtml+xml', 'application/octet-stream'],
            'svg' => ['image/svg+xml', 'application/octet-stream'],
            'text xml' => ['text/xml', 'application/octet-stream'],
            'application xml' => ['application/xml', 'application/octet-stream'],
            'text javascript' => ['text/javascript', 'application/octet-stream'],
            'application javascript' => ['application/javascript', 'application/octet-stream'],
            'old javascript' => ['application/x-javascript', 'application/octet-stream'],
            'empty' => ['', 'application/octet-stream'],
            'only parameters' => ['; charset=utf-8', 'application/octet-stream'],
            'pdf' => ['application/pdf', 'application/pdf'],
            'png' => ['image/png', 'image/png'],
            'plain text keeps its parameters' => ['text/plain; charset=utf-8', 'text/plain; charset=utf-8'],
            'json' => ['application/json', 'application/json'],
            'html lookalike' => ['text/htmlx', 'text/htmlx'],
        ];
    }

    #[DataProvider('mediaTypes')]
    public function test_a_type_a_browser_would_run_is_served_as_opaque_bytes(string $stored, string $expected): void
    {
        $this->assertSame($expected, $this->invokePrivate('downloadMediaType', $stored));
    }

    private function invokePrivate(string $method, string $argument): string
    {
        $service = app(AttachmentStorageService::class);
        $reflection = new ReflectionMethod($service, $method);

        return $reflection->invoke($service, $argument);
    }
}
