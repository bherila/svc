<?php

namespace App\Services\AgentApi;

use App\Models\AgentMutationAudit;
use App\Models\AgentMutationReceipt;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

final class AgentMutationReceiptService
{
    /** @param array<string, mixed> $payload
     * @param callable(): list<string> $callback
     * @return list<string> */
    public function run(User $user, Workspace $workspace, string $clientId, string $operation, string $key, array $payload, callable $callback): array
    {
        $digest = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $existing = AgentMutationReceipt::query()->where([
            'user_id' => $user->id, 'oauth_client_id' => $clientId, 'operation' => $operation, 'idempotency_key' => $key,
        ])->first();
        if ($existing !== null) {
            abort_unless(hash_equals($existing->request_digest, $digest), 409, 'The idempotency key was already used with a different request.');

            return $this->ids($existing);
        }

        try {
            $ids = $callback();
            AgentMutationReceipt::query()->create([
                'user_id' => $user->id, 'oauth_client_id' => $clientId, 'operation' => $operation, 'idempotency_key' => $key,
                'request_digest' => $digest, 'result_public_ids' => $ids,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->run($user, $workspace, $clientId, $operation, $key, $payload, $callback);
        }

        AgentMutationAudit::query()->create([
            'user_id' => $user->id, 'oauth_client_id' => $clientId, 'workspace_id' => $workspace->id,
            'operation' => $operation, 'affected_public_ids' => $ids, 'request_id' => (string) Str::uuid(), 'outcome' => 'success',
        ]);

        return $ids;
    }

    /** @return list<string> */
    private function ids(AgentMutationReceipt $receipt): array
    {
        $decoded = json_decode((string) $receipt->getRawOriginal('result_public_ids'), true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }
}
