<?php

namespace Tests\Unit\Mcp;

use App\Services\Mcp\AgentMcpApiFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AgentMcpApiFailureTest extends TestCase
{
    /** @param array<string, mixed>|null $body */
    #[DataProvider('responses')]
    public function test_only_expected_api_refusals_are_passed_through(int $status, ?array $body, string $expected): void
    {
        $this->assertSame($expected, AgentMcpApiFailure::message($status, $body));
    }

    /** @return iterable<string, array{int, array<string, mixed>|null, string}> */
    public static function responses(): iterable
    {
        $generic = 'The SVC API request could not be completed.';
        yield 'stale state' => [409, ['message' => 'The invoice changed. Reload it and try again.'], 'The invoice changed. Reload it and try again.'];
        yield 'domain refusal' => [422, ['message' => 'The due date cannot precede the issue date.'], 'The due date cannot precede the issue date.'];
        yield 'first validation error' => [422, ['message' => 'The given data was invalid.', 'errors' => ['due_date' => ['The due date must be a date.', 'Ignored second error.'], 'notes' => ['Ignored later field.']]], 'The given data was invalid. The due date must be a date.'];
        yield 'validation without message' => [422, ['errors' => ['due_date' => ['The due date must be a date.']]], 'The due date must be a date.'];
        yield 'conflict validation' => [409, ['errors' => ['expected_version' => ['Reload the invoice.']]], 'Reload the invoice.'];
        yield 'duplicate message' => [422, ['message' => 'Reload the invoice.', 'errors' => ['invoice' => ['Reload the invoice.']]], 'Reload the invoice.'];
        yield 'trimmed text' => [422, ['message' => '  Refused.  ', 'errors' => ['field' => [' ', 4, ['invalid'], '  Try again.  ']]], 'Refused. Try again.'];
        yield 'malformed errors' => [422, ['message' => 'Refused.', 'errors' => 'invalid'], 'Refused.'];
        yield 'skip malformed fields' => [422, ['errors' => ['bad' => 'invalid', 'empty' => [], 'field' => [null, ' ', 'Try again.']]], 'Try again.'];
        yield 'non-string message' => [409, ['message' => ['internal' => 'detail']], $generic];
        yield 'empty message' => [422, ['message' => ' ', 'errors' => []], $generic];
        yield 'empty response' => [409, null, $generic];
        yield 'empty validation response' => [422, null, $generic];
        foreach ([400, 401, 404, 408, 410, 421, 423, 429, 500, 503] as $status) {
            yield 'conceal '.$status => [$status, ['message' => 'Internal synthetic detail.', 'errors' => ['field' => ['Internal synthetic error.']]], $generic];
        }
        yield 'permission refusal' => [403, ['message' => 'Internal synthetic detail.'], 'This connection lacks the required permission.'];
    }
}
