<?php

namespace Tests\Unit\Http;

use App\Http\Controllers\CspReportController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The collector's parsing and sanitising, without a route: the routed behaviour is in Tests\Feature\Http\CspReportControllerTest. */
#[CoversClass(CspReportController::class)]
final class CspReportControllerTest extends TestCase
{
    /** @var list<array{string, array<string, mixed>}> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->logged = [];
        Log::shouldReceive('warning')->andReturnUsing(function (string $message, array $context = []): void {
            $this->logged[] = [$message, $context];
        });
    }

    /** @return array{int, string} status, body */
    private function send(string $type, string $body, ?string $declaredLength = null): array
    {
        $server = ['CONTENT_TYPE' => $type];
        if ($declaredLength !== null) {
            $server['HTTP_CONTENT_LENGTH'] = $declaredLength;
        }
        $response = (new CspReportController)(Request::create('/api/csp-reports', 'POST', [], [], [], $server, $body));

        return [$response->getStatusCode(), (string) $response->getContent()];
    }

    /** @param  array<mixed>  $payload */
    private function legacy(array $payload): array
    {
        return $this->send('application/csp-report', json_encode(['csp-report' => $payload], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> The one logged context. */
    private function only(): array
    {
        $this->assertCount(1, $this->logged);
        $this->assertSame('csp.violation', $this->logged[0][0]);

        return $this->logged[0][1];
    }

    public function test_a_report_is_summarised_to_three_fields_and_answered_with_nothing(): void
    {
        [$status, $body] = $this->legacy([
            'document-uri' => 'https://svc.example.test/workspaces/0b3c1c8e-6f0e-4d3e-9a52-0123456789AB/clients?token=secret#frag',
            'effective-directive' => 'script-src-elem',
            'blocked-uri' => 'https://Evil.Example.Test:8443/x.js?cookie=abc',
            'original-policy' => "default-src 'self'",
        ]);

        $this->assertSame(204, $status);
        $this->assertSame('', $body);
        $this->assertSame(['directive' => 'script-src-elem', 'blocked' => 'evil.example.test', 'path' => '/workspaces/{id}/clients'], $this->only());
    }

    public function test_the_directive_comes_from_the_first_name_that_is_present(): void
    {
        $this->legacy(['effective-directive' => 'img-src', 'violated-directive' => 'default-src']);
        $this->legacy(['violated-directive' => "style-src 'self' https://fonts.bunny.net"]);
        $this->send('application/reports+json', json_encode([['type' => 'csp-violation', 'body' => ['effectiveDirective' => 'font-src']]], JSON_THROW_ON_ERROR));

        $this->assertSame(['img-src', 'style-src', 'font-src'], array_map(static fn (array $line): string => $line[1]['directive'], $this->logged));
    }

    public function test_a_reporting_api_batch_reads_only_csp_violations_and_at_most_ten(): void
    {
        $reports = [['type' => 'deprecation', 'body' => ['id' => 'x']], 'nope', 7, ['type' => 'csp-violation', 'body' => 'str']];
        foreach (range(1, 12) as $number) {
            $reports[] = ['type' => 'csp-violation', 'body' => [
                'effectiveDirective' => 'img-src', 'blockedURL' => 'https://img'.$number.'.example.test/p.png', 'documentURL' => 'https://svc.example.test/dashboard',
            ]];
        }

        [$status] = $this->send('application/reports+json', json_encode($reports, JSON_THROW_ON_ERROR));

        $this->assertSame(204, $status);
        $this->assertCount(CspReportController::MAX_REPORTS, $this->logged);
        $this->assertSame(['directive' => 'img-src', 'blocked' => 'img1.example.test', 'path' => '/dashboard'], $this->logged[0][1]);
        $this->assertSame('img10.example.test', $this->logged[9][1]['blocked']);
    }

    public function test_only_the_two_report_media_types_are_read(): void
    {
        $this->assertSame(204, $this->send('application/csp-report; charset=utf-8', '{"csp-report":{}}')[0]);
        $this->assertSame(204, $this->send(' APPLICATION/REPORTS+JSON ', '[]')[0]);
        $this->assertCount(1, $this->logged);

        foreach (['application/json', 'text/plain', 'multipart/form-data', 'application/x-www-form-urlencoded', ''] as $type) {
            $this->assertSame(415, $this->send($type, '{"csp-report":{}}')[0], $type);
        }
        $this->assertCount(1, $this->logged);
    }

    public function test_a_body_over_the_cap_is_refused_before_it_is_read(): void
    {
        $over = json_encode(['csp-report' => ['x' => str_repeat('a', CspReportController::MAX_BYTES)]], JSON_THROW_ON_ERROR);
        $this->assertSame(413, $this->send('application/csp-report', $over)[0]);
        $this->assertSame(413, $this->send('application/csp-report', '{"csp-report":{}}', (string) (CspReportController::MAX_BYTES + 1))[0]);
        $this->assertSame([], $this->logged);
    }

    public function test_the_cap_itself_is_inclusive_by_body_and_by_declared_length(): void
    {
        $empty = json_encode(['csp-report' => ['x' => '']], JSON_THROW_ON_ERROR);
        $exact = json_encode(['csp-report' => ['x' => str_repeat('a', CspReportController::MAX_BYTES - strlen($empty))]], JSON_THROW_ON_ERROR);
        $this->assertSame(CspReportController::MAX_BYTES, strlen($exact));

        $this->assertSame(204, $this->send('application/csp-report', $exact, (string) CspReportController::MAX_BYTES)[0]);
        $this->assertSame(204, $this->send('application/csp-report', $exact, 'not a number')[0]);
        $this->assertCount(2, $this->logged);
    }

    /** @return array<string, array{string}> */
    public static function malformed(): array
    {
        return ['text' => ['not json'], 'empty' => [''], 'string' => ['"a string"'], 'number' => ['123'], 'truncated' => ['{"csp-report": '], 'too deep' => [str_repeat('[', 20).str_repeat(']', 20)]];
    }

    #[DataProvider('malformed')]
    public function test_a_body_that_is_not_a_json_object_or_list_is_refused_without_an_echo(string $body): void
    {
        [$status, $response] = $this->send('application/csp-report', $body);

        $this->assertSame(400, $status);
        $this->assertSame('', $response);
        $this->assertSame([], $this->logged);
    }

    public function test_unexpected_shapes_log_nothing_or_only_safe_unknowns(): void
    {
        $this->legacy([]);
        $this->assertSame(['directive' => 'unknown', 'blocked' => 'unknown', 'path' => 'unknown'], $this->only());

        $this->logged = [];
        $this->send('application/csp-report', '{"csp-report":"str"}');
        $this->send('application/reports+json', '{"a":1}');
        $this->assertSame([], $this->logged);
    }

    /** @return array<string, array{array<string, mixed>, array{directive: string, blocked: string, path: string}}> */
    public static function sanitised(): array
    {
        return [
            'a directive with injected text' => [['effective-directive' => "script-src'; DROP TABLE users;--"], ['directive' => 'unknown', 'blocked' => 'unknown', 'path' => 'unknown']],
            'a directive of exactly 40 letters' => [['effective-directive' => str_repeat('a', 40)], ['directive' => str_repeat('a', 40), 'blocked' => 'unknown', 'path' => 'unknown']],
            'a directive of 41 letters' => [['effective-directive' => str_repeat('a', 41)], ['directive' => 'unknown', 'blocked' => 'unknown', 'path' => 'unknown']],
            'an upper-case directive' => [['effective-directive' => 'IMG-SRC'], ['directive' => 'img-src', 'blocked' => 'unknown', 'path' => 'unknown']],
            'a padded directive' => [['effective-directive' => '  img-src  '], ['directive' => 'img-src', 'blocked' => 'unknown', 'path' => 'unknown']],
            'a directive that is not text' => [['effective-directive' => ['x']], ['directive' => 'unknown', 'blocked' => 'unknown', 'path' => 'unknown']],
            'a javascript url' => [['blocked-uri' => 'javascript:alert(1)'], ['directive' => 'unknown', 'blocked' => 'other', 'path' => 'unknown']],
            'a keyword' => [['blocked-uri' => 'inline'], ['directive' => 'unknown', 'blocked' => 'inline', 'path' => 'unknown']],
            'an upper-case keyword' => [['blocked-uri' => 'EVAL'], ['directive' => 'unknown', 'blocked' => 'eval', 'path' => 'unknown']],
            'every keyword is kept' => [['blocked-uri' => 'wasm-eval'], ['directive' => 'unknown', 'blocked' => 'wasm-eval', 'path' => 'unknown']],
            'data and blob' => [['blocked-uri' => 'data'], ['directive' => 'unknown', 'blocked' => 'data', 'path' => 'unknown']],
            'an empty blocked uri' => [['blocked-uri' => ''], ['directive' => 'unknown', 'blocked' => 'unknown', 'path' => 'unknown']],
            'a blocked uri that is not text' => [['blocked-uri' => 5], ['directive' => 'unknown', 'blocked' => 'unknown', 'path' => 'unknown']],
            'a host of 100 characters' => [['blocked-uri' => 'https://'.str_repeat('h', 100).'/x'], ['directive' => 'unknown', 'blocked' => str_repeat('h', 100), 'path' => 'unknown']],
            'a host of 101 characters' => [['blocked-uri' => 'https://'.str_repeat('h', 101).'/x'], ['directive' => 'unknown', 'blocked' => 'other', 'path' => 'unknown']],
            'an ip literal' => [['blocked-uri' => 'http://[::1]:5173/@vite/client'], ['directive' => 'unknown', 'blocked' => '[::1]', 'path' => 'unknown']],
            'a host with a hostile character' => [['blocked-uri' => 'https://exa"mple.test/x'], ['directive' => 'unknown', 'blocked' => 'other', 'path' => 'unknown']],
            'credentials never reach the log' => [['blocked-uri' => 'https://user:pass@cdn.example.test/x?token=t'], ['directive' => 'unknown', 'blocked' => 'cdn.example.test', 'path' => 'unknown']],
            'a path with hostile characters' => [['document-uri' => 'https://svc.example.test/a b/<script>/x'], ['directive' => 'unknown', 'blocked' => 'unknown', 'path' => '/a?b/?script?/x']],
            'a path keeps its safe punctuation' => [['document-uri' => 'https://svc.example.test/a-b_c.d~e/{f}'], ['directive' => 'unknown', 'blocked' => 'unknown', 'path' => '/a-b_c.d~e/{f}']],
            'a path with a query' => [['document-uri' => 'https://svc.example.test/pay?client_secret=cs_test_synthetic#x'], ['directive' => 'unknown', 'blocked' => 'unknown', 'path' => '/pay']],
            'a path of exactly 120 characters' => [['document-uri' => 'https://svc.example.test/'.str_repeat('x', 119)], ['directive' => 'unknown', 'blocked' => 'unknown', 'path' => '/'.str_repeat('x', 119)]],
            'a path over 120 characters' => [['document-uri' => 'https://svc.example.test/'.str_repeat('x', 400)], ['directive' => 'unknown', 'blocked' => 'unknown', 'path' => '/'.str_repeat('x', 119)]],
            'a bare origin has no path' => [['document-uri' => 'https://svc.example.test'], ['directive' => 'unknown', 'blocked' => 'unknown', 'path' => 'unknown']],
            'a relative path is not a document uri' => [['document-uri' => 'not a url'], ['directive' => 'unknown', 'blocked' => 'unknown', 'path' => 'unknown']],
            'a document uri that is not text' => [['document-uri' => ['x']], ['directive' => 'unknown', 'blocked' => 'unknown', 'path' => 'unknown']],
            'a record id in any case is dropped' => [['document-uri' => 'https://svc.example.test/c/0B3C1C8E-6F0E-4D3E-9A52-0123456789ab/x'], ['directive' => 'unknown', 'blocked' => 'unknown', 'path' => '/c/{id}/x']],
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array{directive: string, blocked: string, path: string}  $expected
     */
    #[DataProvider('sanitised')]
    public function test_every_logged_field_is_reduced_to_a_short_fixed_alphabet(array $fields, array $expected): void
    {
        $this->legacy($fields);

        $this->assertSame($expected, $this->only());
    }

    public function test_nothing_else_the_browser_sent_reaches_the_log(): void
    {
        $this->legacy([
            'effective-directive' => 'script-src',
            'script-sample' => 'synthetic-secret-sample',
            'source-file' => 'https://svc.example.test/app.js',
            'referrer' => 'https://elsewhere.example.test/',
            'original-policy' => 'synthetic-policy',
            'status-code' => 200,
        ]);

        $line = $this->only();
        $this->assertSame(['directive', 'blocked', 'path'], array_keys($line));
        $this->assertStringNotContainsString('synthetic', (string) json_encode($this->logged));
    }
}
