<?php

declare(strict_types=1);

namespace App\Support\Review;

/**
 * The projects a grant reaches, in the form the approval queue narrows by:
 * null is the whole client, a list is exactly those projects. A grant for one
 * project never reaches another.
 */
final class ReviewerGrantScope
{
    /** @return list<int>|null */
    public static function projectIds(?int $projectId): ?array
    {
        return $projectId === null ? null : [$projectId];
    }

    /** The stored form of "no project", which a unique key can hold where NULL would not. */
    public static function projectKey(?int $projectId): int
    {
        return $projectId ?? 0;
    }
}
