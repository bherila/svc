<?php

namespace Tests\Feature\Payments;

use App\Models\Workspace;
use App\Models\WorkspacePaymentConnection;
use App\Services\Payments\PaymentConnectionResolver;
use App\Services\Payments\StripeConnectionHealth;
use App\Services\Payments\WorkspacePaymentConnections;
use App\Support\Payments\ConnectionOrigin;
use App\Support\Payments\PaymentProcessor;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
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
            $this->assertNull($source->fresh()->getRawOriginal('stripe_refused_credential_version'));
            $this->assertNull($borrower->fresh()->getRawOriginal('stripe_refused_credential_version'));
            $this->assertNull($legacy->fresh()->getRawOriginal('stripe_refused_credential_version'));
            $this->assertArrayNotHasKey('stripe_credential_version', $source->fresh()->toArray());
            $this->assertArrayNotHasKey('stripe_refused_credential_version', $source->fresh()->toArray());
        } finally {
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
        }
    }

    public function test_an_old_a_refusal_arriving_after_b_was_saved_is_not_attributed_to_b_by_migration(): void
    {
        $this->bootProbeDatabase('stripe_credential_migration');
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'stripe_credential_migration', '--force' => true]));
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('stripe_credential_migration');
        Schema::clearResolvedInstance('db.schema');
        try {
            $this->travelTo('2030-12-01 15:01:00');
            $migration = require database_path('migrations/2026_11_23_090000_bind_stripe_health_to_credential_generation.php');
            $migration->down();
            $workspace = $this->workspace('old-response-after-save');
            $old = $this->connection($workspace, ['active_slot' => null, 'secret' => null,
                'connected_at' => '2030-12-01 13:00:00', 'revoked_at' => '2030-12-01 14:00:00']);
            $replacement = $this->connection($workspace, ['secret' => 'sk_test_synthetic_preupgrade_B', 'connected_at' => '2030-12-01 14:00:00', 'endpoint_token' => str_repeat('B', 40)]);
            // Reconstruct the old bug: A's in-flight response wrote only this
            // timestamp after B saved. The old schema retained no key identity.
            DB::table('workspaces')->where('id', $workspace->id)->update(['stripe_refused_at' => '2030-12-01 15:00:00']);

            $migration->up();

            $this->assertNotSame($old->id, $replacement->id);
            $this->assertSame('connection:'.$replacement->id.':source:'.$replacement->id, $workspace->fresh()->getRawOriginal('stripe_credential_version'));
            $this->assertNull($workspace->fresh()->getRawOriginal('stripe_refused_credential_version'), 'Current B cannot prove which key produced the historical timestamp.');
            $this->assertSame('2030-12-01 15:00:00', $workspace->fresh()->getRawOriginal('stripe_refused_at'));
            $health = new StripeConnectionHealth(new WorkspacePaymentConnections);
            $this->assertNotNull($health->refusedAt($workspace), 'Unknown historical markers keep the previous conservative compatibility behavior.');
            $this->assertTrue($health->pauses($workspace));
        } finally {
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function changesDuringBackfill(): iterable
    {
        yield 'reconnect' => [false];
        yield 'revoke' => [true];
    }

    #[DataProvider('changesDuringBackfill')]
    public function test_a_credential_change_while_a_backfill_chunk_is_in_flight_keeps_its_new_generation_and_health(bool $revoke): void
    {
        $this->bootProbeDatabase('stripe_credential_migration');
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'stripe_credential_migration', '--force' => true]));
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('stripe_credential_migration');
        Schema::clearResolvedInstance('db.schema');
        try {
            $this->travelTo('2031-01-01 12:00:00');
            $migration = require database_path('migrations/2026_11_23_090000_bind_stripe_health_to_credential_generation.php');
            $migration->down();
            $workspace = $this->workspace('reconnect');
            $old = $this->connection($workspace);
            $oldConnections = new WorkspacePaymentConnections;
            $oldAccess = (new PaymentConnectionResolver($oldConnections))->stripe($workspace);
            $this->assertNotNull($oldAccess);
            $reconnected = false;
            $replacement = null;
            DB::listen(function (QueryExecuted $query) use ($workspace, $old, $revoke, &$reconnected, &$replacement): void {
                if ($reconnected || ! str_contains($query->sql, 'source_account')) {
                    return;
                }
                $reconnected = true;
                $connections = new WorkspacePaymentConnections;
                if ($revoke) {
                    $this->assertTrue($connections->revoke($old, null));

                    return;
                }
                $replacement = $connections->connect($workspace, PaymentProcessor::Stripe, ConnectionOrigin::Entered, null, [
                    'external_account_id' => 'acct_synthetic_migration', 'secret' => 'sk_test_synthetic_reconnect_during_backfill',
                ]);
                $access = (new PaymentConnectionResolver($connections))->stripe($workspace);
                $this->assertNotNull($access);
                $this->assertTrue((new StripeConnectionHealth($connections))->refuse($workspace, $access));
            });

            $migration->up();

            $this->assertTrue($reconnected);
            if ($revoke) {
                $this->assertSame('disconnected', $workspace->fresh()->getRawOriginal('stripe_credential_version'));
                $this->assertNull($workspace->fresh()->getRawOriginal('stripe_refused_at'));
                $this->assertFalse((new StripeConnectionHealth($oldConnections))->refuse($workspace, $oldAccess));
            } else {
                $this->assertNotNull($replacement);
                $this->assertSame('connection:'.$replacement->id.':source:'.$replacement->id, $workspace->fresh()->getRawOriginal('stripe_credential_version'));
                $this->assertSame('2031-01-01 12:00:00', $workspace->fresh()->getRawOriginal('stripe_refused_at'));
                $this->assertSame('connection:'.$replacement->id.':source:'.$replacement->id, $workspace->fresh()->getRawOriginal('stripe_refused_credential_version'));
            }
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
