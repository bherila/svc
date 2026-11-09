<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * The Content-Security-Policy violation collector (`report-uri` / `report-to`).
 *
 * The caller is any browser, signed in or not, so nothing it sends is trusted: the body is capped,
 * only the two report media types are read, at most {@see self::MAX_REPORTS} reports are looked at,
 * and what reaches the log is a short summary of three fields (directive, blocked host, document
 * path), each reduced to a fixed alphabet and length. The raw JSON, the query string, a URL's
 * credentials and the user agent are never stored, and the response is always empty: nothing the
 * browser sent is echoed back. The log, not the database, holds the summary.
 */
final class CspReportController extends Controller
{
    public const int MAX_BYTES = 8192;

    public const int MAX_REPORTS = 10;

    private const array TYPES = ['application/csp-report', 'application/reports+json'];

    /** The blocked "URIs" that are keywords, not hosts. */
    private const array KEYWORDS = ['inline', 'eval', 'self', 'data', 'blob', 'wasm-eval', 'trusted-types-policy', 'trusted-types-sink'];

    public function __invoke(Request $request): Response
    {
        $length = $request->headers->get('Content-Length');
        if (is_numeric($length) && $length > self::MAX_BYTES) {
            return response()->noContent(413);
        }

        $type = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));
        if (! in_array($type, self::TYPES, true)) {
            return response()->noContent(415);
        }

        // A chunked body carries no Content-Length, and a caller can lie in one: the bytes themselves are measured too.
        $body = $request->getContent();
        if (strlen($body) > self::MAX_BYTES) {
            return response()->noContent(413);
        }

        $decoded = json_decode($body, true, 8);
        if (! is_array($decoded)) {
            return response()->noContent(400);
        }

        foreach (array_slice($this->reports($decoded), 0, self::MAX_REPORTS) as $report) {
            Log::warning('csp.violation', $report);
        }

        return response()->noContent();
    }

    /**
     * @param  array<mixed>  $decoded
     * @return list<array{directive: string, blocked: string, path: string}>
     */
    private function reports(array $decoded): array
    {
        // application/csp-report: {"csp-report": {...}}. application/reports+json: [{"type": "csp-violation", "body": {...}}].
        $candidates = isset($decoded['csp-report']) ? [$decoded['csp-report']] : array_map(
            static fn (mixed $entry): mixed => is_array($entry) && ($entry['type'] ?? null) === 'csp-violation' ? ($entry['body'] ?? null) : null,
            $decoded,
        );

        $reports = [];
        foreach ($candidates as $fields) {
            if (! is_array($fields)) {
                continue;
            }
            $reports[] = [
                'directive' => $this->directive($fields['effective-directive'] ?? $fields['effectiveDirective'] ?? $fields['violated-directive'] ?? null),
                'blocked' => $this->blocked($fields['blocked-uri'] ?? $fields['blockedURL'] ?? null),
                'path' => $this->path($fields['document-uri'] ?? $fields['documentURL'] ?? null),
            ];
        }

        return $reports;
    }

    private function directive(mixed $value): string
    {
        // "script-src-elem 'self' ..." (a violated-directive carries its sources): the name is the first word.
        $name = is_string($value) ? strtolower(explode(' ', trim($value))[0]) : '';

        return preg_match('/^[a-z-]{1,40}\z/', $name) === 1 ? $name : 'unknown';
    }

    private function blocked(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return 'unknown';
        }
        $keyword = strtolower($value);
        if (in_array($keyword, self::KEYWORDS, true)) {
            return $keyword;
        }
        $host = parse_url($value, PHP_URL_HOST);

        return is_string($host) && preg_match('/^[A-Za-z0-9.\[\]:-]{1,100}\z/', $host) === 1 ? strtolower($host) : 'other';
    }

    private function path(mixed $value): string
    {
        $path = is_string($value) ? parse_url($value, PHP_URL_PATH) : null;
        if (! is_string($path) || ! str_starts_with($path, '/')) {
            return 'unknown';
        }
        // A record's id is not worth keeping in a log line.
        $path = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '{id}', $path) ?? $path;
        $path = preg_replace('/[^A-Za-z0-9._~\/{}-]/', '?', $path) ?? $path;

        return substr($path, 0, 120);
    }
}
