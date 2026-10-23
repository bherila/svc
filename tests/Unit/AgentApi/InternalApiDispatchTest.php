<?php

namespace Tests\Unit\AgentApi;

use App\Support\AgentApi\InternalApiDispatch;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InternalApiDispatchTest extends TestCase
{
    public function test_it_is_active_only_while_a_dispatch_runs_including_a_nested_one(): void
    {
        $dispatch = new InternalApiDispatch;
        $this->assertFalse($dispatch->active());

        $seen = $dispatch->run(fn (): array => [$dispatch->active(), $dispatch->run(fn (): bool => $dispatch->active()), $dispatch->active()]);

        $this->assertSame([true, true, true], $seen);
        $this->assertFalse($dispatch->active());
    }

    public function test_a_failed_dispatch_does_not_leave_it_active(): void
    {
        $dispatch = new InternalApiDispatch;
        try {
            $dispatch->run(static fn () => throw new RuntimeException('Synthetic failure.'));
            $this->fail('The failure was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic failure.', $exception->getMessage());
        }

        $this->assertFalse($dispatch->active());
    }
}
