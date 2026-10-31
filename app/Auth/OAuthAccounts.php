<?php

namespace App\Auth;

use App\Support\Concurrency\Locks;
use BWH\Auth\OAuth\Login\RelyingPartyAccounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * SVC's account rules for provider sign-in. Everything but the lock is the package default.
 *
 * The lock goes through {@see Locks} so `LockOrderConformanceTest` sees sign-in's acquisitions:
 * the subject's row first, then the holder of the address about to be written, which is the
 * order the shared rules take and the order this application's own sign-in always took.
 */
final class OAuthAccounts extends RelyingPartyAccounts
{
    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function lockForUpdate(Builder $query): Builder
    {
        return $query->tap(Locks::forUpdate());
    }
}
