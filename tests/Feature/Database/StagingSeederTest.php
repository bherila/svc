<?php

namespace Tests\Feature\Database;

use App\Models\ClientCompanyMembership;
use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\StagingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class StagingSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_no_users_and_refuses_until_both_identities_have_signed_in(): void
    {
        try {
            (new StagingSeeder)->run();
            $this->fail('The seeder must refuse before the owner identity has signed in.');
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString('staging-owner@example.test', $refusal->getMessage());
        }
        // An unbound account with the right email is not enough: SVC would never adopt it at sign-in.
        User::factory()->create(['email' => 'staging-owner@example.test', 'oauth_provider' => null, 'oauth_subject' => null]);
        $this->signedIn('staging-portal@example.test', 'synthetic-portal');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nothing was changed.');
        try {
            (new StagingSeeder)->run();
        } finally {
            $this->assertSame(0, Workspace::query()->count());
            $this->assertSame(2, User::query()->count());
        }
    }

    public function test_it_seeds_one_synthetic_workspace_idempotently_on_reserved_domains(): void
    {
        $owner = $this->signedIn('staging-owner@example.test', 'synthetic-owner');
        $portal = $this->signedIn('staging-portal@example.test', 'synthetic-portal');
        $this->seed(StagingSeeder::class);
        $this->seed(StagingSeeder::class);

        $workspace = Workspace::query()->where('slug', StagingSeeder::WORKSPACE_SLUG)->firstOrFail();
        $this->assertSame(1, Workspace::query()->count());
        $this->assertSame(4, ClientInvoice::query()->where('workspace_id', $workspace->id)->where('status', 'issued')->count());
        $this->assertSame(1, ClientCompanyMembership::query()->count());
        $this->assertSame(2, User::query()->count(), 'The seeder creates no users.');
        $this->assertTrue($workspace->memberships()->where('user_id', $owner->id)->where('role', 'owner')->exists());
        $this->assertSame($portal->id, ClientCompanyMembership::query()->sole()->user_id);
        $this->assertSame(25000, (int) ClientInvoice::query()->where('invoice_number', 'INV-STAGING-1')->value('balance_amount'));
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->expectException(RuntimeException::class);
        (new StagingSeeder)->run();
    }

    private function signedIn(string $email, string $subject): User
    {
        return User::factory()->create(['email' => $email, 'oauth_provider' => 'synthetic-idp', 'oauth_subject' => $subject]);
    }
}
