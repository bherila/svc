<?php

namespace Tests\Feature\AgentApi;

use App\Models\AgentMutationAudit;
use App\Models\AgentMutationReceipt;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentMutationExecutor;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class LegacyReceiptNamespaceTest extends TestCase
{
    use RefreshDatabase;

    public static function ambiguousReceipts(): iterable
    {
        foreach (['completed', 'pending', 'unknown', 'namespace_guard'] as $status) {
            foreach ([false, true] as $withoutWorkspace) {
                yield $status.($withoutWorkspace ? '-unattributed' : '-scoped') => [$status, $withoutWorkspace];
            }
        }
    }

    #[DataProvider('ambiguousReceipts')]
    public function test_ambiguous_legacy_receipts_refuse_all_clients_and_payloads_without_disclosing_or_reassigning(string $status, bool $withoutWorkspace): void
    {
        [$user, $workspace] = $this->tenant();
        $legacy = $this->receipt($user, $withoutWorkspace ? null : $workspace, 'testing-client', $status);
        $before = $legacy->fresh()->getAttributes();
        foreach (['synthetic-client-a', 'synthetic-client-b'] as $client) {
            foreach ([['value' => 1], ['value' => 2]] as $payload) {
                $this->conflict(fn () => app(AgentMutationExecutor::class)->run($user, $workspace, $client, 'tasks.create', 'synthetic-key', $payload, function (): array {
                    $this->fail('A legacy collision must never execute the mutation.');
                }));
            }
        }
        $this->assertSame($before, $legacy->fresh()->getAttributes());
        $this->assertDatabaseCount('agent_mutation_receipts', 1);
        $this->assertSame(4, AgentMutationAudit::query()->where('workspace_id', $workspace->id)->where('outcome', 'failed')->count());
        $this->assertSame([[]], AgentMutationAudit::query()->where('workspace_id', $workspace->id)->get()->pluck('affected_public_ids')->unique()->values()->all());
    }

    public function test_distinct_clients_keep_independent_receipts_behind_one_legacy_guard(): void
    {
        [$user, $workspace] = $this->tenant();
        $calls = 0;
        $execute = function () use (&$calls): array {
            $calls++;

            return [(string) Str::uuid()];
        };
        $executor = app(AgentMutationExecutor::class);
        $first = $executor->run($user, $workspace, 'synthetic-client-a', 'tasks.create', 'synthetic-key', [], $execute);
        $second = $executor->run($user, $workspace, 'synthetic-client-b', 'tasks.create', 'synthetic-key', [], $execute);
        $this->assertNotSame($first, $second);
        $this->assertSame($first, $executor->run($user, $workspace, 'synthetic-client-a', 'tasks.create', 'synthetic-key', [], $execute));
        $this->assertSame($second, $executor->run($user, $workspace, 'synthetic-client-b', 'tasks.create', 'synthetic-key', [], $execute));
        $this->assertSame(2, $calls);
        $this->assertDatabaseCount('agent_mutation_receipts', 3);
        $guard = AgentMutationReceipt::query()->where('workspace_id', $workspace->id)->where('oauth_client_id', 'testing-client')->sole();
        $this->assertSame('namespace_guard', $guard->status);
        $this->assertSame([], $guard->result_public_ids);
        $this->assertNull($guard->completed_at);
        // The old executor always inserts first, before calling domain code. Its
        // duplicate insert sees the guard, then refuses its non-completed state.
        try {
            $this->receipt($user, $workspace, 'testing-client', 'pending');
            $this->fail('The old writer must collide before executing.');
        } catch (UniqueConstraintViolationException) {
            $this->assertNotSame('completed', $guard->status);
            $this->assertNotSame(hash('sha256', '[]'), $guard->request_digest);
        }
    }

    public function test_an_existing_authenticated_replay_commits_its_guard_and_still_checks_current_authorization(): void
    {
        [$user, $workspace] = $this->tenant();
        $receipt = $this->receipt($user, $workspace, 'synthetic-client-a', 'completed');
        $guardCalls = 0;
        $result = app(AgentMutationExecutor::class)->run($user, $workspace, 'synthetic-client-a', 'tasks.create', 'synthetic-key', ['value' => 1], function (): array {
            $this->fail('An authenticated replay cannot run again.');
        }, function (array $ids) use (&$guardCalls, $receipt): void {
            $guardCalls++;
            $this->assertSame($receipt->result_public_ids, $ids);
        });
        $this->assertSame(1, $guardCalls);
        $this->assertSame($receipt->result_public_ids, $result);
        $this->assertDatabaseHas('agent_mutation_receipts', ['workspace_id' => $workspace->id, 'oauth_client_id' => 'testing-client', 'status' => 'namespace_guard']);
        try {
            app(AgentMutationExecutor::class)->run($user, $workspace, 'synthetic-client-a', 'tasks.create', 'synthetic-key', ['value' => 1], fn (): array => [], fn () => abort(403));
            $this->fail('A replay must still enforce current authorization.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_failed_callback_rolls_back_both_reservations_and_audits_failure(): void
    {
        [$user, $workspace] = $this->tenant();
        $this->conflict(fn () => app(AgentMutationExecutor::class)->run($user, $workspace, 'synthetic-client-a', 'tasks.create', 'synthetic-key', [], fn () => abort(409)));
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
        $this->assertDatabaseHas('agent_mutation_audits', ['workspace_id' => $workspace->id, 'oauth_client_id' => 'synthetic-client-a', 'outcome' => 'failed', 'error_category' => 'conflict']);
        // With no committed new effect, an old writer can legitimately reserve.
        $this->assertTrue($this->receipt($user, $workspace, 'testing-client', 'pending')->exists);
    }

    public function test_legacy_collisions_are_isolated_by_known_workspace_actor_operation_and_key(): void
    {
        [$user, $workspace] = $this->tenant();
        [, $otherWorkspace] = $this->tenant();
        $otherUser = User::factory()->create();
        $this->receipt($user, $otherWorkspace, 'testing-client', 'completed');
        $this->receipt($otherUser, $workspace, 'testing-client', 'completed');
        $this->receipt($user, $workspace, 'testing-client', 'completed', 'tasks.update');
        $this->receipt($user, $workspace, 'testing-client', 'completed', 'tasks.create', 'other-key');
        $result = app(AgentMutationExecutor::class)->run($user, $workspace, 'synthetic-client-a', 'tasks.create', 'synthetic-key', [], fn (): array => ['synthetic-result']);
        $this->assertSame(['synthetic-result'], $result);
    }

    public function test_a_guard_with_result_ids_completion_or_changed_digest_is_never_trusted(): void
    {
        [$user, $workspace] = $this->tenant();
        $executor = app(AgentMutationExecutor::class);
        foreach (['result_public_ids' => ['synthetic-private-result'], 'completed_at' => now(), 'request_digest' => str_repeat('0', 64)] as $field => $value) {
            $key = 'synthetic-corrupt-'.$field;
            $executor->run($user, $workspace, 'synthetic-client-a', 'tasks.create', $key, [], fn (): array => []);
            $guard = AgentMutationReceipt::query()->where('workspace_id', $workspace->id)->where('oauth_client_id', 'testing-client')->where('idempotency_key', $key)->sole();
            $guard->forceFill([$field => $value])->save();
            $this->conflict(fn () => $executor->run($user, $workspace, 'synthetic-client-b', 'tasks.create', $key, [], function (): array {
                $this->fail('A malformed guard must never grant namespace ownership.');
            }));
        }
    }

    public function test_direct_callers_cannot_use_the_reserved_legacy_identity(): void
    {
        [$user, $workspace] = $this->tenant();
        $this->conflict(fn () => app(AgentMutationExecutor::class)->run($user, $workspace, 'testing-client', 'tasks.create', 'synthetic-key', [], function (): array {
            $this->fail('A direct MCP workflow must not write under the reserved identity.');
        }));
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
    }

    public function test_all_receipt_reads_name_the_requested_or_explicitly_unknown_workspace(): void
    {
        [$user, $workspace] = $this->tenant();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $sql = str_replace(['`', '"'], '', strtolower($query->sql));
            if (str_starts_with($sql, 'select') && str_contains($sql, 'from agent_mutation_receipts')) {
                $queries[] = $sql;
            }
        });
        $executor = app(AgentMutationExecutor::class);
        $executor->run($user, $workspace, 'synthetic-client-a', 'tasks.create', 'synthetic-key', [], fn (): array => []);
        $executor->run($user, $workspace, 'synthetic-client-a', 'tasks.create', 'synthetic-key', [], fn (): array => []);
        $this->assertNotEmpty($queries);
        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/\bwhere\b.*\bworkspace_id (?:= \?|is null)/', $sql);
        }
    }

    public static function legacyOperations(): iterable
    {
        foreach (['time_entries.log', 'time_entries.update', 'time_entries.delete', 'time_entries.approve', 'tasks.create', 'tasks.update', 'invoices.create_draft', 'invoices.update_draft', 'invoices.discard_draft', 'invoices.issue', 'invoices.send', 'invoices.void'] as $operation) {
            yield $operation => [$operation];
        }
    }

    #[DataProvider('legacyOperations')]
    public function test_each_original_operation_fences_old_writers(string $operation): void
    {
        [$user, $workspace] = $this->tenant();
        $this->receipt($user, $workspace, 'testing-client', 'completed', $operation);
        $this->conflict(fn () => app(AgentMutationExecutor::class)->run($user, $workspace, 'synthetic-client-a', $operation, 'synthetic-key', [], function (): array {
            $this->fail('Every original operation must fence ambiguous receipts.');
        }));
    }

    /** @return array{User, Workspace} */
    private function tenant(): array
    {
        return [User::factory()->create(), Workspace::query()->create(['name' => 'Synthetic receipt workspace', 'slug' => 'synthetic-'.Str::uuid()])];
    }

    private function receipt(User $user, ?Workspace $workspace, string $client, string $status, string $operation = 'tasks.create', string $key = 'synthetic-key'): AgentMutationReceipt
    {
        return AgentMutationReceipt::query()->create(['workspace_id' => $workspace?->id, 'user_id' => $user->id, 'oauth_client_id' => $client, 'operation' => $operation, 'idempotency_key' => $key, 'request_digest' => hash('sha256', '{"value":1}'), 'status' => $status, 'result_public_ids' => [(string) Str::uuid()]]);
    }

    private function conflict(callable $run): void
    {
        try {
            $run();
            $this->fail('An ambiguous key must fail closed.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertStringNotContainsString('result', $exception->getMessage());
        }
    }
}
