<?php

namespace App\Services\AgentApi;

use App\Models\AgentMutationReceipt;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Concurrency\Locks;

/** Compatibility fence for REST receipts whose authenticated client was never stored. */
final class LegacyAgentReceiptNamespace
{
    public const string CLIENT_ID = 'testing-client';

    private const string STATUS = 'namespace_guard';

    private const array OPERATIONS = [
        'time_entries.log', 'time_entries.update', 'time_entries.delete', 'time_entries.approve',
        'tasks.create', 'tasks.update',
        'invoices.create_draft', 'invoices.update_draft', 'invoices.discard_draft',
        'invoices.issue', 'invoices.send', 'invoices.void',
    ];

    /** Must run inside the same transaction as the authenticated receipt and mutation. */
    public function reserve(User $user, Workspace $workspace, string $clientId, string $operation, string $key): void
    {
        abort_if($clientId === self::CLIENT_ID, 409, 'The legacy receipt namespace cannot accept mutations.');
        if (! in_array($operation, self::OPERATIONS, true)) {
            return;
        }

        $identity = [
            'user_id' => $user->id,
            'oauth_client_id' => self::CLIENT_ID,
            'operation' => $operation,
            'idempotency_key' => $key,
        ];
        // Pre-workspace receipts have no trustworthy tenant provenance. Never replay them.
        $unknownWorkspace = AgentMutationReceipt::query()->whereNull('workspace_id')->where($identity)->tap(Locks::forUpdate())->first();
        abort_if($unknownWorkspace !== null, 409, 'This legacy idempotency key requires reconciliation before another mutation.');

        $digest = hash('sha256', 'svc:authenticated-receipt-namespace:v1');
        // The original unique key also fences old in-flight writers: whichever insert wins
        // owns the key. Old code cannot replay a non-completed guard with no result IDs.
        $guard = AgentMutationReceipt::query()->where('workspace_id', $workspace->id)->tap(Locks::forUpdate())->createOrFirst([
            ...$identity,
            'workspace_id' => $workspace->id,
        ], [
            'request_digest' => $digest,
            'status' => self::STATUS,
            'result_public_ids' => [],
        ]);
        abort_unless(
            $guard->status === self::STATUS
            && hash_equals($digest, $guard->request_digest)
            && $guard->getAttribute('result_public_ids') === []
            && $guard->completed_at === null,
            409,
            'This legacy idempotency key requires reconciliation before another mutation.',
        );
    }
}
