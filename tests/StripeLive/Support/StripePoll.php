<?php

namespace Tests\StripeLive\Support;

use Closure;

/**
 * Bounded polling for the Events API, which is eventually consistent: an event
 * can take several seconds to appear after the call that caused it. The clock
 * and the sleep are injectable so the backoff is testable without waiting.
 */
final class StripePoll
{
    /**
     * @param  Closure(): float|null  $now  seconds, monotonic
     * @param  Closure(int): void|null  $sleep  milliseconds
     */
    public function __construct(private readonly ?Closure $now = null, private readonly ?Closure $sleep = null) {}

    /**
     * Try until the attempt returns something other than null, or the timeout
     * passes. The attempt always runs at least once; waits grow by half each
     * time up to five seconds. Null means it never produced an answer.
     *
     * @template T
     *
     * @param  Closure(): (T|null)  $attempt
     * @return T|null
     */
    public function until(Closure $attempt, int $timeoutSeconds, int $intervalMilliseconds = 1000): mixed
    {
        $now = $this->now ?? static fn (): float => hrtime(true) / 1_000_000_000;
        $sleep = $this->sleep ?? static function (int $milliseconds): void {
            usleep($milliseconds * 1000);
        };
        $deadline = $now() + $timeoutSeconds;
        $wait = max(1, $intervalMilliseconds);

        while (true) {
            $result = $attempt();
            if ($result !== null) {
                return $result;
            }
            $remainingMilliseconds = (int) floor(($deadline - $now()) * 1000);
            if ($remainingMilliseconds <= 0) {
                return null;
            }
            $sleep(min($wait, $remainingMilliseconds));
            $wait = min(5000, (int) ceil($wait * 1.5));
        }
    }
}
