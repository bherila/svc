<?php

declare(strict_types=1);

namespace App\Support\Accounting;

use App\Exceptions\InvalidAgentApiCursor;

/**
 * The snapshot watermark of one pass over an accounting feed: fixed by the
 * pass's first page and carried in every cursor after it, so entries recorded
 * while the pass is paged are left for the next pass instead of shifting pages
 * under the reader. The next pass sends it as `updated_since`.
 */
final class FeedWatermark
{
    /**
     * @param  string|null  $requested  the watermark the caller named, `Y-m-d H:i:s` in UTC
     * @param  string  $latest  the newest watermark a pass may have, now less the lag
     *
     * @throws InvalidAgentApiCursor when a watermark is named that the cursor was not minted with
     */
    public static function resolve(?string $requested, ?FeedCursor $cursor, string $latest): string
    {
        if ($cursor === null) {
            return $requested ?? $latest;
        }
        if ($requested !== null && $requested !== $cursor->watermark) {
            throw new InvalidAgentApiCursor('watermark does not match the cursor.');
        }

        return $cursor->watermark;
    }
}
