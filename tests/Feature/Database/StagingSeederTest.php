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

    public function test_it_seeds_one_synthetic_workspace_idempotently_on_reserved_domains(): void
    {
        $this->seed(StagingSeeder::class);
        $this->seed(StagingSeeder::class);

        $workspace = Workspace::query()->where('slug', StagingSeeder::WORKSPACE_SLUG)->firstOrFail();
        $this->assertSame(1, Workspace::query()->count());
        $this->assertSame(4, ClientInvoice::query()->where('workspace_id', $workspace->id)->where('status', 'issued')->count());
        $this->assertSame(1, ClientCompanyMembership::query()->count());
        $this->assertSame(['staging-owner@example.test', 'staging-portal@example.test'], User::query()->orderBy('email')->pluck('email')->all());
        $this->assertSame(25000, (int) ClientInvoice::query()->where('invoice_number', 'INV-STAGING-1')->value('balance_amount'));
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->expectException(RuntimeException::class);
        (new StagingSeeder)->run();
    }
}
