<?php

namespace App\Auth;

use App\Support\Concurrency\Locks;
use BWH\Auth\OAuth\Login\RelyingPartyAccounts;
use BWH\Auth\OAuth\OAuthIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * SVC's account rules for provider sign-in. Everything but the lock and the `email_verified`
 * refusal is the package default.
 *
 * The lock goes through {@see Locks} so `LockOrderConformanceTest` sees sign-in's acquisitions:
 * the subject's row first, then the holder of the address about to be written, which is the
 * order the shared rules take and the order this application's own sign-in always took.
 *
 * The inherited `newAccountAttributes()` and `refreshedAttributes()` stamp `email_verified_at`
 * and take the provider's email on every sign-in. Grants that bind by email rely on the
 * provider verifying address changes: see "Security assumption: the identity provider
 * verifies email changes" in SECURITY.md before overriding them or adding a provider.
 *
 * Both refuse, before anything is written, an identity whose provider sent `email_verified`
 * as anything but true ({@see OAuthIdentity::$emailVerified}): such an address is never used to
 * create, link or update an account. An identity without the claim is trusted as before.
 */
final class OAuthAccounts extends RelyingPartyAccounts
{
    /** @return array<string, mixed> */
    public function newAccountAttributes(OAuthIdentity $identity): array
    {
        $this->refuseUnverifiedEmail($identity);

        return parent::newAccountAttributes($identity);
    }

    /** @return array<string, mixed> */
    public function refreshedAttributes(Model $account, OAuthIdentity $identity): array
    {
        $this->refuseUnverifiedEmail($identity);

        return parent::refreshedAttributes($account, $identity);
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function lockForUpdate(Builder $query): Builder
    {
        return $query->tap(Locks::forUpdate());
    }

    private function refuseUnverifiedEmail(OAuthIdentity $identity): void
    {
        if ($identity->emailVerified === false) {
            throw new UnverifiedProviderEmail($identity->provider);
        }
    }
}
