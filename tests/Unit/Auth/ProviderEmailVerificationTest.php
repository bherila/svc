<?php

namespace Tests\Unit\Auth;

use App\Auth\OAuthAccounts;
use App\Auth\UnverifiedProviderEmail;
use App\Models\User;
use BWH\Auth\OAuth\OAuthIdentity;
use Tests\TestCase;

final class ProviderEmailVerificationTest extends TestCase
{
    public function test_an_explicit_unverified_identity_is_refused_before_account_creation(): void
    {
        $this->expectException(UnverifiedProviderEmail::class);

        (new OAuthAccounts)->newAccountAttributes($this->identity(false));
    }

    public function test_an_explicit_unverified_identity_is_refused_before_account_refresh(): void
    {
        $this->expectException(UnverifiedProviderEmail::class);

        (new OAuthAccounts)->refreshedAttributes(new User, $this->identity(false));
    }

    public function test_verified_and_absent_claims_preserve_the_existing_provider_trust_contract(): void
    {
        foreach ([true, null] as $claim) {
            $attributes = (new OAuthAccounts)->newAccountAttributes($this->identity($claim));
            $this->assertSame('synthetic@example.test', $attributes['email']);
            $this->assertNotNull($attributes['email_verified_at']);
        }
    }

    public function test_claims_belong_to_the_passed_identity_and_cannot_leak_between_callbacks(): void
    {
        $accounts = new OAuthAccounts;
        $this->assertSame('synthetic@example.test', $accounts->newAccountAttributes($this->identity(true))['email']);
        try {
            $accounts->newAccountAttributes($this->identity(false));
            $this->fail('An explicit false claim must be refused.');
        } catch (UnverifiedProviderEmail $refusal) {
            $this->assertSame('test-provider', $refusal->provider);
        }
        $this->assertSame('synthetic@example.test', $accounts->newAccountAttributes($this->identity(null))['email']);
    }

    private function identity(?bool $claim): OAuthIdentity
    {
        return new OAuthIdentity('test-provider', 'synthetic-subject', 'Synthetic Person', 'synthetic@example.test', emailVerified: $claim);
    }
}
