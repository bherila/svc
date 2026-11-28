<?php

namespace Tests\Unit\Payments;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePaymentConnection;
use App\Services\Payments\WorkspacePaymentConnections;
use App\Support\Payments\ConnectionOrigin;
use App\Support\Payments\PaymentProcessor;
use DomainException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

#[CoversClass(WorkspacePaymentConnections::class)]
final class PaymentConnectionReuseTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{PaymentProcessor}> */
    public static function processors(): iterable
    {
        yield 'Stripe' => [PaymentProcessor::Stripe];
        yield 'Square' => [PaymentProcessor::Square];
    }

    #[DataProvider('processors')]
    public function test_replacement_locks_in_id_order_and_retires_only_the_selected_processor_and_its_borrowers(PaymentProcessor $processor): void
    {
        $actor = User::factory()->create();
        $target = $this->workspace($actor, 'target');
        $sourceWorkspace = $this->workspace($actor, 'source');
        $borrowerWorkspace = $this->workspace($actor, 'borrower');
        $unrelatedWorkspace = $this->workspace(User::factory()->create(), 'unrelated');
        $connections = new WorkspacePaymentConnections;
        $otherProcessor = $processor === PaymentProcessor::Stripe ? PaymentProcessor::Square : PaymentProcessor::Stripe;
        $other = $this->connect($connections, $target, $otherProcessor, $actor);
        $old = $this->connect($connections, $target, $processor, $actor);
        $source = $this->connect($connections, $sourceWorkspace, $processor, $actor);
        $borrower = $connections->reuse($borrowerWorkspace, $old, $actor);
        $unrelated = $this->connect($connections, $unrelatedWorkspace, $processor, null);
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'workspace_payment_connections')) {
                $queries[] = $query->sql;
            }
        });

        $created = $connections->reuse($target, $source, $actor);

        $this->assertNotEmpty($queries);
        $this->assertMatchesRegularExpression('/order by [`"]id[`"] asc/i', $queries[0], 'The first connection lock must rank source and target together, not take the chosen source first.');
        $this->assertSame($source->id, $created->reuses_connection_id);
        $this->assertSame($target->id, $created->workspace_id);
        $this->assertSame($source->external_account_id, $created->external_account_id);
        $this->assertSame($processor->value, $created->processor);
        $this->assertNotNull($old->fresh()->revoked_at);
        $this->assertNotNull($borrower->fresh()->revoked_at);
        $this->assertNull($source->fresh()->revoked_at);
        $this->assertNull($other->fresh()->revoked_at);
        $this->assertNull($unrelated->fresh()->revoked_at);
        $this->assertSame($source->id, $connections->credentialSource($created)?->id);
        $this->assertSame($created->id, $connections->active($target, $processor)?->id);
        if ($processor === PaymentProcessor::Stripe) {
            $this->assertSame('disconnected', $borrowerWorkspace->fresh()->stripe_credential_version);
            $this->assertNotSame('disconnected', $target->fresh()->stripe_credential_version);
        }
    }

    #[DataProvider('processors')]
    public function test_a_source_revoked_after_eligibility_was_read_cannot_retire_the_target_or_admit_a_borrower(PaymentProcessor $processor): void
    {
        $actor = User::factory()->create();
        $target = $this->workspace($actor, 'target');
        $sourceWorkspace = $this->workspace($actor, 'source');
        $connections = new WorkspacePaymentConnections;
        $old = $this->connect($connections, $target, $processor, $actor);
        $source = $this->connect($connections, $sourceWorkspace, $processor, $actor);
        $snapshot = $source->fresh();
        $connections->revoke($source, $actor);

        foreach ([$source, $snapshot] as $unusable) {
            try {
                $connections->reuse($target, $unusable, $actor);
                $this->fail('A stale eligibility result must be revalidated under the source lock.');
            } catch (DomainException $exception) {
                $this->assertSame('That connection cannot be reused.', $exception->getMessage());
            }
        }

        $this->assertNull($old->fresh()->revoked_at);
        $this->assertSame($old->id, $connections->active($target, $processor)?->id);
        $this->assertSame(0, WorkspacePaymentConnection::query()->where('reuses_connection_id', $source->id)->whereNull('revoked_at')->count());
    }

    #[DataProvider('processors')]
    public function test_neither_a_source_nor_a_borrower_can_be_reused_back_into_its_own_workspace(PaymentProcessor $processor): void
    {
        $actor = User::factory()->create();
        $sourceWorkspace = $this->workspace($actor, 'source');
        $borrowerWorkspace = $this->workspace($actor, 'borrower');
        $connections = new WorkspacePaymentConnections;
        $source = $this->connect($connections, $sourceWorkspace, $processor, $actor);
        $borrower = $connections->reuse($borrowerWorkspace, $source, $actor);

        foreach ([$sourceWorkspace, $borrowerWorkspace] as $target) {
            try {
                $connections->reuse($target, $borrower, $actor);
                $this->fail('Reuse must not retire the source behind its own new borrower.');
            } catch (DomainException $exception) {
                $this->assertSame('That connection cannot be reused.', $exception->getMessage());
            }
        }

        $this->assertNull($source->fresh()->revoked_at);
        $this->assertNull($borrower->fresh()->revoked_at);
        $this->assertSame(2, WorkspacePaymentConnection::query()->whereNull('revoked_at')->count());
    }

    private function workspace(User $owner, string $slug): Workspace
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic '.$slug, 'slug' => 'synthetic-'.$slug]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        return $workspace;
    }

    private function connect(WorkspacePaymentConnections $connections, Workspace $workspace, PaymentProcessor $processor, ?User $actor): WorkspacePaymentConnection
    {
        return $connections->connect($workspace, $processor, ConnectionOrigin::Entered, $actor, [
            'external_account_id' => 'synthetic_'.$processor->value.'_'.$workspace->id,
            'secret' => 'sk_test_synthetic_reuse_'.$workspace->id,
        ]);
    }
}
