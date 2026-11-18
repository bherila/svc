<?php

namespace Tests\Feature\Payments;

use App\Models\Workspace;
use App\Models\WorkspacePaymentConnection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

final class StripeCredentialGenerationMigrationTest extends TestCase
{
    use UsesAProbeDatabase;

    public function test_existing_owned_reused_revoked_and_legacy_paths_are_backfilled_without_losing_current_refusals(): void
    {
        $this->bootProbeDatabase('stripe_credential_migration');
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'stripe_credential_migration', '--force' => true]));
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('stripe_credential_migration');
        Schema::clearResolvedInstance('db.schema');
        try {
            $migration = require database_path('migrations/2026_11_23_090000_bind_stripe_health_to_credential_generation.php');
            $migration->down();
            $source = $this->workspace('source');
            $borrower = $this->workspace('borrower');
            $revoked = $this->workspace('revoked');
            $legacy = $this->workspace('legacy');
            $obsolete = $this->workspace('obsolete');
            $invalidBorrower = $this->workspace('invalid-borrower');
            $own = $this->connection($source);
            $reused = $this->connection($borrower, ['reuses_connection_id' => $own->id, 'origin' => 'reused', 'secret' => null]);
            $this->connection($revoked, ['revoked_at' => '2030-12-01 16:00:00', 'active_slot' => null]);
            $newer = $this->connection($obsolete, ['connected_at' => '2030-12-01 16:00:00']);
            $this->connection($invalidBorrower, ['reuses_connection_id' => $own->id, 'origin' => 'reused', 'external_account_id' => 'acct_synthetic_wrong', 'secret' => null]);
            DB::table('workspaces')->whereIn('id', [$source->id, $borrower->id, $obsolete->id])->update(['stripe_refused_at' => '2030-12-01 15:00:00']);

            $migration->up();

            $this->assertSame('connection:'.$own->id.':source:'.$own->id, $source->fresh()->getRawOriginal('stripe_credential_version'));
            $this->assertSame('connection:'.$reused->id.':source:'.$own->id, $borrower->fresh()->getRawOriginal('stripe_credential_version'));
            $this->assertSame('connection:'.$newer->id.':source:'.$newer->id, $obsolete->fresh()->getRawOriginal('stripe_credential_version'));
            $this->assertSame('disconnected', $revoked->fresh()->getRawOriginal('stripe_credential_version'));
            $this->assertSame('disconnected', $invalidBorrower->fresh()->getRawOriginal('stripe_credential_version'));
            $this->assertNull($legacy->fresh()->getRawOriginal('stripe_credential_version'));
            $this->assertSame('2030-12-01 15:00:00', $source->fresh()->getRawOriginal('stripe_refused_at'));
            $this->assertSame('2030-12-01 15:00:00', $borrower->fresh()->getRawOriginal('stripe_refused_at'));
            $this->assertNull($obsolete->fresh()->getRawOriginal('stripe_refused_at'));
            $this->assertArrayNotHasKey('stripe_credential_version', $source->fresh()->toArray());
        } finally {
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
        }
    }

    private function workspace(string $slug): Workspace
    {
        return Workspace::query()->create(['name' => 'Synthetic generation '.$slug, 'slug' => 'synthetic-generation-'.$slug]);
    }

    /** @param array<string, mixed> $overrides */
    private function connection(Workspace $workspace, array $overrides = []): WorkspacePaymentConnection
    {
        return WorkspacePaymentConnection::query()->create($overrides + [
            'workspace_id' => $workspace->id,
            'processor' => 'stripe',
            'external_account_id' => 'acct_synthetic_migration',
            'origin' => 'entered',
            'secret' => 'sk_test_synthetic_migration',
            'endpoint_token' => str_pad((string) $workspace->id, 40, 'x'),
            'connected_at' => '2030-12-01 14:00:00',
            'active_slot' => 'stripe',
        ]);
    }
}
