<?php

namespace Tests\Feature\Payments;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Payments\PaymentConnectionResolver;
use App\Services\Payments\StripeConnectionHealth;
use App\Services\Payments\WorkspacePaymentConnections;
use App\Support\Payments\ConnectionOrigin;
use App\Support\Payments\PaymentProcessor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

/** A request's ordinary reads may predate a reconnect; health must use the current workspace fence. */
final class StripeCredentialGenerationSnapshotTest extends TestCase
{
    use UsesAProbeDatabase;

    /** @return iterable<string, array{bool}> */
    public static function paths(): iterable
    {
        yield 'own connection' => [false];
        yield 'legacy environment' => [true];
    }

    #[DataProvider('paths')]
    public function test_an_old_repeatable_read_snapshot_cannot_refuse_or_accept_replacement_credentials(bool $legacy): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('The MariaDB lane verifies a real REPEATABLE READ snapshot across two sessions.');
        }
        $this->bootProbeDatabase('stripe_generation_snapshot');
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'stripe_generation_snapshot', '--force' => true]));
        $original = DB::getDefaultConnection();
        config(['database.connections.stripe_generation_writer' => config('database.connections.stripe_generation_snapshot')]);
        DB::setDefaultConnection('stripe_generation_snapshot');
        Schema::clearResolvedInstance('db.schema');
        try {
            config(['services.stripe.secret_key' => 'sk_test_synthetic_snapshot_A']);
            $workspace = Workspace::query()->create(['name' => 'Synthetic snapshot', 'slug' => 'synthetic-snapshot']);
            $connections = new WorkspacePaymentConnections;
            if (! $legacy) {
                $connections->connect($workspace, PaymentProcessor::Stripe, ConnectionOrigin::Entered, null, [
                    'external_account_id' => 'acct_synthetic_snapshot', 'secret' => 'sk_test_synthetic_snapshot_A',
                ]);
            }
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            DB::beginTransaction();
            $oldVersion = DB::table('workspaces')->where('id', $workspace->id)->value('stripe_credential_version');
            $old = (new PaymentConnectionResolver($connections))->stripe($workspace);
            $this->assertNotNull($old);
            DB::setDefaultConnection('stripe_generation_writer');
            $writerConnections = new WorkspacePaymentConnections;
            $writerConnections->connect($workspace, PaymentProcessor::Stripe, ConnectionOrigin::Entered, null, [
                'external_account_id' => 'acct_synthetic_snapshot', 'secret' => 'sk_test_synthetic_snapshot_B',
            ]);
            $current = (new PaymentConnectionResolver($writerConnections))->stripe($workspace);
            $this->assertNotNull($current);
            $writerHealth = new StripeConnectionHealth($writerConnections);
            $this->assertTrue($writerHealth->refuse($workspace, $current));
            DB::setDefaultConnection('stripe_generation_snapshot');
            $this->assertSame($oldVersion, DB::table('workspaces')->where('id', $workspace->id)->value('stripe_credential_version'), 'The control must still have the old ordinary-read snapshot.');
            $health = new StripeConnectionHealth($connections);

            $this->assertFalse($health->refuse($workspace, $old));
            $health->accepted($workspace, $old);
            DB::rollBack();
            DB::setDefaultConnection('stripe_generation_writer');
            $this->assertNotNull($writerHealth->refusedAt($workspace));
            $this->assertTrue($writerHealth->pauses($workspace));
        } finally {
            while (DB::connection('stripe_generation_snapshot')->transactionLevel() > 0) {
                DB::connection('stripe_generation_snapshot')->rollBack();
            }
            DB::purge('stripe_generation_writer');
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
        }
    }

    public function test_source_revocation_invalidates_a_dependent_added_after_its_ordinary_snapshot(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('The MariaDB lane verifies a real REPEATABLE READ snapshot across two sessions.');
        }
        $this->bootProbeDatabase('stripe_generation_snapshot');
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'stripe_generation_snapshot', '--force' => true]));
        $original = DB::getDefaultConnection();
        config(['database.connections.stripe_generation_writer' => config('database.connections.stripe_generation_snapshot')]);
        DB::setDefaultConnection('stripe_generation_snapshot');
        Schema::clearResolvedInstance('db.schema');
        try {
            $owner = User::factory()->create();
            $sourceWorkspace = Workspace::query()->create(['name' => 'Synthetic source', 'slug' => 'synthetic-source']);
            $target = Workspace::query()->create(['name' => 'Synthetic borrower', 'slug' => 'synthetic-borrower']);
            foreach ([$sourceWorkspace, $target] as $workspace) {
                $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
            }
            $connections = new WorkspacePaymentConnections;
            $source = $connections->connect($sourceWorkspace, PaymentProcessor::Stripe, ConnectionOrigin::Entered, $owner, [
                'external_account_id' => 'acct_synthetic_snapshot', 'secret' => 'sk_test_synthetic_source_A',
            ]);
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            DB::beginTransaction();
            $this->assertSame(0, DB::table('workspace_payment_connections')->where('reuses_connection_id', $source->id)->count());
            DB::setDefaultConnection('stripe_generation_writer');
            $writerConnections = new WorkspacePaymentConnections;
            $writerConnections->reuse($target, $source, $owner);
            $old = (new PaymentConnectionResolver($writerConnections))->stripe($target);
            $this->assertNotNull($old);
            DB::setDefaultConnection('stripe_generation_snapshot');
            $this->assertSame(0, DB::table('workspace_payment_connections')->where('reuses_connection_id', $source->id)->count());

            $this->assertTrue($connections->revoke($source, $owner));
            DB::commit();
            DB::setDefaultConnection('stripe_generation_writer');
            $this->assertSame('disconnected', DB::table('workspaces')->where('id', $target->id)->value('stripe_credential_version'));
            $health = new StripeConnectionHealth($writerConnections);
            $this->assertFalse($health->refuse($target, $old));
            $this->assertNull($health->refusedAt($target));
        } finally {
            while (DB::connection('stripe_generation_snapshot')->transactionLevel() > 0) {
                DB::connection('stripe_generation_snapshot')->rollBack();
            }
            DB::purge('stripe_generation_writer');
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
        }
    }
}
