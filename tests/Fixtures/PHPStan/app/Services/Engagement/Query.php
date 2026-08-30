<?php

declare(strict_types=1);

namespace Tests\Fixtures\PHPStan\app\Services\Engagement;

final class Query
{
    public function where(mixed ...$arguments): self
    {
        return $this;
    }

    public function whereNotIn(mixed ...$arguments): self
    {
        return $this;
    }
}

function allowedOutsideBilling(Query $query): void
{
    $query->whereNotIn('status', ['void']);
    $query->where('status', '!=', 'void');
}
