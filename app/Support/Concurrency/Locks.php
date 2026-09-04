<?php

namespace App\Support\Concurrency;

use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The one place this application takes a pessimistic row lock.
 *
 * In production this is `lockForUpdate()` and nothing else: same SQL, same
 * builder, same rows. What it adds is a name for what is being locked and,
 * where a test has asked for it, a record of the order the locks were taken in.
 * `DisallowRawLockForUpdateRule` refuses `lockForUpdate()` anywhere else, so the
 * record is complete by construction rather than by convention.
 *
 * Review kept finding locking gaps one at a time - a claim released with no
 * lock at all, a freeze that read outside the lock it depended on, check-then-act
 * guards re-verified only after someone asked - and there was no single place to
 * look up what order anything was meant to be taken in. This is that place;
 * `docs/client-management/concurrency.md` is its prose.
 *
 * ## Why it is spelled `->tap(Locks::forUpdate())`
 *
 * Because it goes exactly where `->lockForUpdate()` went, in the middle of the
 * chain that was already there. A wrapping call - `Locks::acquire($query)` -
 * would have meant rewriting fifty chains around a new set of parentheses, and
 * a mis-parenthesised chain that still compiles is precisely the silent
 * locking change this registry exists to make impossible. It also keeps the
 * builder's own type: `tap` returns `$this`, so a locked
 * `Builder<ClientInvoice>` is still a `Builder<ClientInvoice>` and every
 * `firstOrFail()` downstream keeps its model type.
 */
final class Locks
{
    /**
     * Take `FOR UPDATE` on the rows a query selects, in the chain that selects
     * them:
     *
     *     $locked = ClientInvoice::query()
     *         ->whereKey($invoice->id)
     *         ->tap(Locks::forUpdate())
     *         ->firstOrFail();
     *
     * The lock is not taken at this point - `lockForUpdate()` only sets a flag
     * the grammar renders when the query runs - so a chain that is built and
     * never executed records an acquisition it did not make. That is the safe
     * direction to be wrong in: a recorded lock that was not taken can only
     * make a sequence look worse ordered than it is, while an unrecorded one
     * makes a real inversion invisible.
     *
     * @return Closure(EloquentBuilder<covariant Model>|QueryBuilder): void
     */
    public static function forUpdate(): Closure
    {
        return static function (EloquentBuilder|QueryBuilder $query): void {
            LockOrderRecorder::record(LockResource::forQuery($query));

            $query->lockForUpdate();
        };
    }
}
