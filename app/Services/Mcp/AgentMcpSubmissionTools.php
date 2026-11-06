<?php

namespace App\Services\Mcp;

use App\Services\Submission\SubmissionService;
use Mcp\Capability\Attribute\Schema;

/**
 * The submission tools. Each calls its REST operation, so the author-only rule,
 * the workspace scoping, the idempotency receipt and the cutovers are the
 * route's and cannot differ between the two transports.
 */
final class AgentMcpSubmissionTools
{
    public function __construct(private readonly AgentMcpRestCall $rest) {}

    /** @return array<string, mixed> */
    public function list(#[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(pattern: '^\d{4}-(0[1-9]|1[0-2])$')] ?string $period = null,
        #[Schema(format: 'uuid')] ?string $project_id = null): array
    {
        return $this->rest->call('submissions.list', ['workspace' => $workspace_id],
            query: array_filter(compact('period', 'project_id'), static fn (mixed $value): bool => $value !== null));
    }

    /**
     * @param  list<string>|null  $time_entry_ids
     * @param  list<string>|null  $expense_ids
     * @return array<string, mixed>
     */
    public function submit(#[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        #[Schema(pattern: '^\d{4}-(0[1-9]|1[0-2])$')] ?string $period = null,
        #[Schema(format: 'uuid')] ?string $project_id = null,
        #[Schema(maxItems: SubmissionService::LIMIT)] ?array $time_entry_ids = null,
        #[Schema(maxItems: SubmissionService::LIMIT)] ?array $expense_ids = null): array
    {
        return $this->rest->call('submissions.submit', ['workspace' => $workspace_id],
            array_filter(compact('period', 'project_id', 'time_entry_ids', 'expense_ids'), static fn (mixed $value): bool => $value !== null), $idempotency_key);
    }

    /**
     * @param  list<string>|null  $time_entry_ids
     * @param  list<string>|null  $expense_ids
     * @return array<string, mixed>
     */
    public function withdraw(#[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        #[Schema(pattern: '^\d{4}-(0[1-9]|1[0-2])$')] ?string $period = null,
        #[Schema(format: 'uuid')] ?string $project_id = null,
        #[Schema(maxItems: SubmissionService::LIMIT)] ?array $time_entry_ids = null,
        #[Schema(maxItems: SubmissionService::LIMIT)] ?array $expense_ids = null): array
    {
        return $this->rest->call('submissions.withdraw', ['workspace' => $workspace_id],
            array_filter(compact('period', 'project_id', 'time_entry_ids', 'expense_ids'), static fn (mixed $value): bool => $value !== null), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function approved(#[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(pattern: '^\d{4}-(0[1-9]|1[0-2])$')] ?string $period = null,
        #[Schema(format: 'uuid')] ?string $project_id = null): array
    {
        return $this->rest->call('timesheets.approved', ['workspace' => $workspace_id],
            query: array_filter(compact('period', 'project_id'), static fn (mixed $value): bool => $value !== null));
    }
}
