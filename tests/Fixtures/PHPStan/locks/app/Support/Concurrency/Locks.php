<?php

namespace Tests\Fixtures\PHPStan\locks\app\Support\Concurrency;

use App\Models\ClientInvoice;

/**
 * Stands in for the real helper, at the path the rule exempts.
 *
 * Named the same and placed at the same relative path, because the exemption is
 * by path: a class that merely calls itself `Locks` somewhere else must not
 * inherit it.
 */
final class Locks
{
    public function takesTheRealLock(int $id): ?ClientInvoice
    {
        return ClientInvoice::query()->whereKey($id)->lockForUpdate()->first();
    }
}
