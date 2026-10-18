<?php

namespace Tests\Unit\Square;

use App\Services\Square\SquareReadFailureReason;
use App\Services\Square\SquareRequestFailed;
use PHPUnit\Framework\TestCase;

final class SquareSweepFailureClassificationTest extends TestCase
{
    public function test_local_diagnostics_preserve_http_and_retry_contracts(): void
    {
        foreach ([null, SquareReadFailureReason::Transport] as $reason) {
            $failure = SquareRequestFailed::unavailable($reason);
            $this->assertSame(503, $failure->getStatusCode());
            $this->assertSame(['Retry-After' => '30'], $failure->getHeaders());
            $this->assertSame('SERVICE_UNAVAILABLE', $failure->squareCode);
            $this->assertSame($reason, $failure->reason);
            $this->assertFalse($failure->isRecordSpecific());
        }
        foreach ([null, SquareReadFailureReason::MalformedJson, SquareReadFailureReason::MappingRejected] as $reason) {
            $failure = SquareRequestFailed::unreadable($reason);
            $this->assertSame(502, $failure->getStatusCode());
            $this->assertSame([], $failure->getHeaders());
            $this->assertNull($failure->squareCode);
            $this->assertSame($reason, $failure->reason);
            $this->assertTrue($failure->isRecordSpecific());
            $this->assertSame('Square returned a response svc cannot read.', $failure->getMessage());
            $this->assertNull($failure->getPrevious());
        }
    }

    public function test_only_record_specific_failures_allow_the_sweep_to_continue(): void
    {
        foreach ([SquareRequestFailed::notFound(), SquareRequestFailed::rejected('INVALID_REQUEST_ERROR', 'BAD_REQUEST'), SquareRequestFailed::unreadable()] as $failure) {
            $this->assertTrue($failure->isRecordSpecific());
        }
        foreach ([SquareRequestFailed::notConnected(), SquareRequestFailed::unavailable(), SquareRequestFailed::rateLimited(1), SquareRequestFailed::rateLimitedBySquare(1), SquareRequestFailed::credentialRefused(null, null), SquareRequestFailed::credentialRefused('AUTHENTICATION_ERROR', 'UNAUTHORIZED')] as $failure) {
            $this->assertFalse($failure->isRecordSpecific());
        }
    }
}
