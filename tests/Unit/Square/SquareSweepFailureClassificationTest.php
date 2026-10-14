<?php

namespace Tests\Unit\Square;

use App\Services\Square\SquareRequestFailed;
use PHPUnit\Framework\TestCase;

final class SquareSweepFailureClassificationTest extends TestCase
{
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
