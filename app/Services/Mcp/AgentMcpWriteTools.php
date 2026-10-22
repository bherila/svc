<?php

namespace App\Services\Mcp;

use App\Models\ClientInvoicePayment;
use App\Services\Mcp\Context\McpRequestContext;
use App\Support\AgentApi\ReadView;
use App\Support\Billing\InvoiceEmailDraft;
use App\Support\Billing\ReceivedPaymentData;
use Bherila\McpLaravelBridge\Mcp\RequestArguments;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;

final class AgentMcpWriteTools
{
    public function __construct(
        private readonly AgentMcpRestCall $rest,
        private readonly RequestArguments $requestArguments,
    ) {}

    /** @return array<string,mixed> */
    public function proposalsCreate(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $company_id, #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema(minLength: 1, maxLength: 200)] string $title, #[Schema(pattern: '^[A-Z]{3}$')] string $currency, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key, RequestContext $request): array
    {
        $body = compact('company_id', 'expected_version', 'title', 'currency');
        foreach (['project_id', 'summary', 'terms', 'valid_until', 'is_visible_to_client', 'items'] as $name) {
            if ($this->requestArguments->has($request, $name)) {
                $body[$name] = $this->requestArguments->value($request, $name, null);
            }
        }

        return $this->rest->call('proposals.create', ['workspace' => $workspace_id], $body, $idempotency_key);
    }

    /** @return array<string,mixed> */
    public function proposalsSend(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $proposal_id, #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema] bool $confirm, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('proposals.send', ['workspace' => $workspace_id, 'proposal' => $proposal_id], compact('expected_version', 'confirm'), $idempotency_key);
    }

    /** @return array<string,mixed> */
    public function proposalsAccept(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $proposal_id, #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema] bool $confirm, #[Schema(minLength: 1, maxLength: 200)] string $signer_name, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key, #[Schema(maxLength: 200)] ?string $signer_title = null): array
    {
        return $this->rest->call('proposals.accept', ['workspace' => $workspace_id, 'proposal' => $proposal_id], compact('expected_version', 'confirm', 'signer_name', 'signer_title'), $idempotency_key);
    }

    /** @return array<string,mixed> */
    public function workspacesCreate(#[Schema(minLength: 1, maxLength: 120)] string $name,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('workspaces.create', [], compact('name'), $idempotency_key);
    }

    /** @return array<string,mixed> */
    public function search(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(minLength: 1, maxLength: 200)] string $q): array
    {
        return $this->rest->call('search', ['workspace' => $workspace_id], query: compact('q'));
    }

    /** @return array<string,mixed> */
    public function attachmentsList(#[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(enum: ['company', 'project', 'task', 'proposal', 'agreement', 'invoice'])] string $record_type,
        #[Schema(format: 'uuid')] string $record_id): array
    {
        return $this->rest->call('attachments.list', ['workspace' => $workspace_id, 'recordType' => $record_type, 'recordPublicId' => $record_id], null);
    }

    /** @return array<string,mixed> */
    public function attachmentsGet(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $attachment_id): array
    {
        return $this->rest->call('attachments.get', ['workspace' => $workspace_id, 'attachment' => $attachment_id], null);
    }

    /** @return array<string,mixed> */
    public function attachmentsDownloadUrl(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $attachment_id): array
    {
        return $this->rest->call('attachments.download_url', ['workspace' => $workspace_id, 'attachment' => $attachment_id], null);
    }

    /** Authorizes a later upload; upload the bytes to the returned URL with the same bearer credential.
     * @return array<string,mixed> */
    public function attachmentsUploadUrl(#[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(enum: ['company', 'project', 'task', 'proposal', 'agreement', 'invoice'])] string $record_type,
        #[Schema(format: 'uuid')] string $record_id): array
    {
        return $this->rest->call('attachments.upload_url', ['workspace' => $workspace_id, 'recordType' => $record_type, 'recordPublicId' => $record_id], []);
    }

    /** @return array<string,mixed> */
    public function attachmentsDelete(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $attachment_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version, bool $confirm,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('attachments.delete', ['workspace' => $workspace_id, 'attachment' => $attachment_id], compact('expected_version', 'confirm'), $idempotency_key);
    }

    /**
     * Record money already received. Omitted optional fields mean what the
     * browser form means by them: today, no reference or note, succeeded, and
     * no finance allocation.
     *
     * @param  array<string, mixed>|null  $finance_reconciliation
     * @return array<string, mixed>
     */
    public function paymentsRecord(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $invoice_id,
        #[Schema(minimum: 1)] int $amount,
        #[Schema(pattern: '^[A-Z]{3}$')] string $currency,
        #[Schema(minLength: 1, maxLength: ClientInvoicePayment::METHOD_MAX_LENGTH)] string $method,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema] bool $confirm,
        #[Schema(format: 'date')] ?string $received_on = null,
        #[Schema(maxLength: 255)] ?string $reference = null,
        #[Schema(maxLength: ReceivedPaymentData::NOTES_MAX_LENGTH)] ?string $notes = null,
        #[Schema(enum: ['pending', 'succeeded'])] ?string $status = null,
        #[Schema(format: 'uuid')] ?string $external_finance_transaction_uuid = null,
        ?array $finance_reconciliation = null,
    ): array {
        // The body the tool always sent, nulls included: the route reads an
        // explicit null exactly as an omitted field, so receipts written
        // before the tool reached REST replay unchanged. The route decides
        // finance_reconciliation's finance:reconcile scope from this
        // connection's token.
        return $this->rest->call('payments.record', ['workspace' => $workspace_id],
            compact('invoice_id', 'amount', 'currency', 'received_on', 'method', 'reference', 'notes', 'status', 'external_finance_transaction_uuid', 'finance_reconciliation', 'expected_version', 'confirm'),
            $idempotency_key);
    }

    /**
     * Correct a received payment's method, reference, note or date. Omitted
     * fields are left alone; an explicit null reference or note clears it.
     *
     * @return array<string, mixed>
     */
    public function paymentsCorrect(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $payment_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema(minLength: 1, maxLength: 500)] string $reason,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        #[Schema] bool $confirm,
        RequestContext $request,
        #[Schema(minLength: 1, maxLength: ClientInvoicePayment::METHOD_MAX_LENGTH)] ?string $method = null,
        #[Schema(maxLength: 255)] ?string $reference = null,
        #[Schema(format: 'date')] ?string $received_on = null,
        #[Schema(maxLength: ReceivedPaymentData::NOTES_MAX_LENGTH)] ?string $notes = null,
    ): array {
        return $this->rest->call('payments.correct', ['workspace' => $workspace_id, 'payment' => $payment_id],
            compact('expected_version', 'reason', 'confirm') + $this->provided($request, compact('method', 'reference', 'notes', 'received_on')),
            $idempotency_key);
    }

    /**
     * Move a payment recorded by hand to succeeded, failed or canceled.
     *
     * @return array<string, mixed>
     */
    public function paymentsSetStatus(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $payment_id,
        #[Schema(enum: ['succeeded', 'failed', 'canceled'])] string $status,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema(minLength: 1, maxLength: 500)] string $reason,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        #[Schema] bool $confirm,
    ): array {
        return $this->rest->call('payments.set_status', ['workspace' => $workspace_id, 'payment' => $payment_id], compact('status', 'expected_version', 'reason', 'confirm'), $idempotency_key);
    }

    /**
     * Record money returned to the client against a payment recorded by hand.
     *
     * @return array<string, mixed>
     */
    public function paymentsRefund(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $payment_id,
        #[Schema(minimum: 1)] int $amount,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema(minLength: 1, maxLength: 500)] string $reason,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        #[Schema] bool $confirm,
    ): array {
        return $this->rest->call('payments.refund', ['workspace' => $workspace_id, 'payment' => $payment_id], compact('amount', 'expected_version', 'reason', 'confirm'), $idempotency_key);
    }

    /**
     * Answer a refund recorded in error with a reversing entry (#472): all of
     * it, or `amount` of it when sent.
     *
     * @return array<string, mixed>
     */
    public function paymentsReverseRefund(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $payment_id,
        #[Schema(format: 'uuid')] string $refund_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema(minLength: 1, maxLength: 500)] string $reason,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        #[Schema] bool $confirm,
        RequestContext $request,
        #[Schema(minimum: 1)] ?int $amount = null,
    ): array {
        return $this->rest->call('payments.reverse_refund', ['workspace' => $workspace_id, 'payment' => $payment_id, 'refund' => $refund_id],
            compact('expected_version', 'reason', 'confirm') + $this->provided($request, compact('amount')),
            $idempotency_key);
    }

    public function forContext(McpRequestContext $context): self
    {
        return new self(
            $this->rest->forContext($context),
            $this->requestArguments,
        );
    }

    /** @param list<array<string,mixed>> $entries
     * @return array<string,mixed> */
    public function expensesLog(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(minItems: 1, maxItems: 20)] array $entries, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('expenses.log', ['workspace' => $workspace_id], compact('entries'), $idempotency_key);
    }

    /** @return array<string,mixed> */
    public function expensesUpdate(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $expense_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        #[Schema(format: 'date')] string $spent_on,
        #[Schema(minimum: 1)] int $amount,
        #[Schema(pattern: '^[A-Z]{3}$')] string $currency,
        #[Schema(minLength: 1, maxLength: 2000)] string $description,
        RequestContext $request,
        #[Schema(format: 'uuid')] ?string $project_id = null,
    ): array {
        // An omitted project keeps the attribution; an explicit null clears it.
        return $this->rest->call('expenses.update', ['workspace' => $workspace_id, 'expense' => $expense_id], compact('expected_version', 'spent_on', 'amount', 'currency', 'description') + $this->provided($request, compact('project_id')), $idempotency_key);
    }

    /** @return array<string,mixed> */
    public function expensesDelete(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $expense_id, #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('expenses.delete', ['workspace' => $workspace_id, 'expense' => $expense_id], compact('expected_version'), $idempotency_key);
    }

    /** @return array<string,mixed> */
    public function expensesApprove(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $expense_id, #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('expenses.approve', ['workspace' => $workspace_id, 'expense' => $expense_id], compact('expected_version'), $idempotency_key);
    }

    /** @return array<string,mixed> */
    public function expensesUnapprove(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $expense_id, #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('expenses.unapprove', ['workspace' => $workspace_id, 'expense' => $expense_id], compact('expected_version'), $idempotency_key);
    }

    /** @return array<string,mixed> */
    public function expenseSchedulesList(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] ?string $company_id = null, #[Schema(minimum: 1, maximum: 100)] int $limit = 25, #[Schema(maxLength: 2048)] ?string $cursor = null): array
    {
        return $this->rest->call('expense_schedules.list', ['workspace' => $workspace_id], query: array_map(strval(...), array_filter(compact('company_id', 'limit', 'cursor'), static fn (mixed $value): bool => $value !== null)));
    }

    /** @return array<string,mixed> */
    public function expenseSchedulesCreate(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $company_id, #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema(format: 'date')] string $starts_on, #[Schema(enum: ['monthly', 'quarterly', 'semi_annual', 'annual'])] string $cadence, #[Schema(minimum: 1)] int $amount, #[Schema(pattern: '^[A-Z]{3}$')] string $currency, #[Schema(minLength: 1, maxLength: 2000)] string $description, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key, #[Schema(format: 'uuid')] ?string $project_id = null): array
    {
        return $this->rest->call('expense_schedules.create', ['workspace' => $workspace_id], compact('company_id', 'expected_version', 'starts_on', 'cadence', 'amount', 'currency', 'description', 'project_id'), $idempotency_key);
    }

    /** @return array<string,mixed> */
    public function expenseSchedulesUpdate(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $schedule_id, #[Schema(minLength: 64, maxLength: 64)] string $expected_version, bool $active, #[Schema(minimum: 1)] int $amount, #[Schema(pattern: '^[A-Z]{3}$')] string $currency, #[Schema(minLength: 1, maxLength: 2000)] string $description, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key, #[Schema(format: 'uuid')] ?string $project_id = null): array
    {
        return $this->rest->call('expense_schedules.update', ['workspace' => $workspace_id, 'schedule' => $schedule_id], compact('expected_version', 'active', 'amount', 'currency', 'description', 'project_id'), $idempotency_key);
    }

    /** @return array<string,mixed> */
    public function expenseSchedulesGenerate(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $schedule_id, #[Schema(minLength: 64, maxLength: 64)] string $expected_version, bool $confirm, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('expense_schedules.generate', ['workspace' => $workspace_id, 'schedule' => $schedule_id], compact('expected_version', 'confirm'), $idempotency_key);
    }

    /** @return array<string,mixed> */
    public function expenseReceiptsList(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $expense_id): array
    {
        return $this->rest->call('expenses.receipts.list', ['workspace' => $workspace_id, 'expense' => $expense_id], null);
    }

    /** @return array<string,mixed> */
    public function expenseReceiptsDownload(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $expense_id, #[Schema(format: 'uuid')] string $receipt_id): array
    {
        return $this->rest->call('expenses.receipts.download', ['workspace' => $workspace_id, 'expense' => $expense_id, 'receipt' => $receipt_id], null);
    }

    /** @return array<string,mixed> */
    public function expenseReceiptsUploadUrl(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $expense_id): array
    {
        return $this->rest->call('expenses.receipts.upload_url', ['workspace' => $workspace_id, 'expense' => $expense_id], null);
    }

    /** @return array<string, mixed> */
    public function projectsCreate(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $company_id, string $name, #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key, bool $confirm, ?string $description = null, ?string $repository = null, bool $is_visible_to_client = true): array
    {
        return $this->rest->call('projects.create', ['workspace' => $workspace_id], compact('company_id', 'name', 'expected_version', 'confirm', 'description', 'repository', 'is_visible_to_client'), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function projectsUpdate(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $project_id, #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key, bool $confirm, RequestContext $context, ?string $name = null, ?string $description = null, ?string $repository = null, ?string $status = null, ?bool $is_visible_to_client = null): array
    {
        $body = compact('expected_version', 'confirm');
        foreach (compact('name', 'description', 'repository', 'status', 'is_visible_to_client') as $name => $value) {
            if ($this->requestArguments->has($context, $name)) {
                $body[$name] = $value;
            }
        }

        return $this->rest->call('projects.update', ['workspace' => $workspace_id, 'project' => $project_id], $body, $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function projectsArchive(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $project_id, #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key, bool $confirm): array
    {
        return $this->rest->call('projects.archive', ['workspace' => $workspace_id, 'project' => $project_id], compact('expected_version', 'confirm'), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function projectMembersUpdate(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $project_id, #[Schema(format: 'uuid')] string $user_id, string $role, #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key, bool $confirm): array
    {
        return $this->rest->call('projects.members.update', ['workspace' => $workspace_id, 'project' => $project_id], compact('user_id', 'role', 'expected_version', 'confirm'), $idempotency_key);
    }

    /** @param list<array<string, mixed>> $entries
     * @return array<string, mixed> */
    public function timeEntriesLog(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(minItems: 1, maxItems: 20)] array $entries, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key, bool $approve = false): array
    {
        $flags = app(McpFeatureFlags::class);
        // Approval arriving through log answers to the same kill switch as the
        // approve tool; the route separately enforces the cutover, scope and role.
        if ($approve && ! $flags->enabledFor('time_entries.approve', McpFeatureFlags::WRITE)) {
            throw new ToolCallException('Approving time is not enabled for agents.');
        }

        // `approve` joins the body only when set, so the digest of a plain
        // log - and every receipt written before the flag existed - is unchanged.
        // The read view is time_entries.list's, so it answers to that
        // tool's kill switch here and to its scope in the route.
        return $this->rest->call(
            'time_entries.log',
            ['workspace' => $workspace_id],
            ['entries' => $entries] + ($approve ? ['approve' => true] : []),
            $idempotency_key,
            $flags->enabledFor('time_entries.list', McpFeatureFlags::READ) ? [ReadView::PARAMETER => ReadView::READ] : [],
        );
    }

    /** @return array<string, mixed> */
    public function timeEntriesUpdate(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $entry_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        RequestContext $context,
        #[Schema(format: 'date')] ?string $worked_on = null,
        #[Schema(minimum: 1, maximum: 1440)] ?int $minutes = null,
        #[Schema(maxLength: 10000)] ?string $description = null,
        ?bool $is_billable = null,
        ?bool $is_deferred = null,
        ?bool $is_visible_to_client = null,
        #[Schema(maxLength: 10000)] ?string $client_visible_description = null,
        #[Schema(format: 'uuid')] ?string $task_id = null,
    ): array {
        return $this->rest->call('time_entries.update', ['workspace' => $workspace_id, 'entry' => $entry_id], compact('expected_version') + $this->provided($context, compact('worked_on', 'minutes', 'description', 'is_billable', 'is_deferred', 'is_visible_to_client', 'client_visible_description', 'task_id')), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function timeEntriesDelete(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $entry_id, #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('time_entries.delete', ['workspace' => $workspace_id, 'entry' => $entry_id], compact('expected_version'), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function timeEntriesUnapprove(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $entry_id, #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('time_entries.unapprove', ['workspace' => $workspace_id, 'entry' => $entry_id], compact('expected_version'), $idempotency_key);
    }

    /** @param list<array{id: string, expected_version: string}> $entries
     * @return array<string, mixed> */
    public function timeEntriesApprove(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(minItems: 1, maxItems: 100)] array $entries, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('time_entries.approve', ['workspace' => $workspace_id], compact('entries'), $idempotency_key);
    }

    /** @param list<string>|null $assignee_ids
     * @return array<string, mixed> */
    public function tasksCreate(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $project_id, #[Schema(minLength: 1, maxLength: 255)] string $title, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key, ?string $description = null, ?bool $is_visible_to_client = null, #[Schema(format: 'date')] ?string $starts_on = null, #[Schema(format: 'date')] ?string $due_on = null, #[Schema(maxItems: 10, items: ['type' => 'string', 'format' => 'uuid'])] ?array $assignee_ids = null): array
    {
        return $this->rest->call('tasks.create', ['workspace' => $workspace_id, 'project' => $project_id], array_filter(compact('title', 'description', 'is_visible_to_client', 'starts_on', 'due_on', 'assignee_ids'), static fn (mixed $value): bool => $value !== null), $idempotency_key);
    }

    /** @param list<string>|null $assignee_ids
     * @return array<string, mixed> */
    public function tasksUpdate(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $task_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        RequestContext $context,
        #[Schema(minLength: 1, maxLength: 255)] ?string $title = null,
        #[Schema(maxLength: 10000)] ?string $description = null,
        #[Schema(enum: ['open', 'in_progress', 'completed'])] ?string $status = null,
        ?bool $is_visible_to_client = null,
        #[Schema(format: 'date')] ?string $starts_on = null,
        #[Schema(format: 'date')] ?string $due_on = null,
        #[Schema(maxItems: 10, items: ['type' => 'string', 'format' => 'uuid'])] ?array $assignee_ids = null,
    ): array {
        return $this->rest->call('tasks.update', ['workspace' => $workspace_id, 'task' => $task_id], compact('expected_version') + $this->provided($context, compact('title', 'description', 'status', 'is_visible_to_client', 'starts_on', 'due_on', 'assignee_ids')), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function invoicesHoldDelivery(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $invoice_id, #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('invoices.hold_delivery', ['workspace' => $workspace_id, 'invoice' => $invoice_id], compact('expected_version'), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function invoicesReleaseDelivery(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $invoice_id, #[Schema] bool $confirm, #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('invoices.release_delivery', ['workspace' => $workspace_id, 'invoice' => $invoice_id], compact('expected_version', 'confirm'), $idempotency_key);
    }

    /** @param list<string> $time_entry_ids
     * @return array<string, mixed> */
    public function invoicesAddTime(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $invoice_id, #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema(minItems: 1, maxItems: 100, uniqueItems: true)] array $time_entry_ids, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('invoices.add_time', ['workspace' => $workspace_id, 'invoice' => $invoice_id], compact('expected_version', 'time_entry_ids'), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function invoicesGeneratePeriod(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $agreement_id, #[Schema(format: 'date')] string $period_start, #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema] bool $confirm, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('invoices.generate_period', ['workspace' => $workspace_id, 'agreement' => $agreement_id], compact('period_start', 'expected_version', 'confirm'), $idempotency_key);
    }

    /** @param list<string> $time_entry_ids
     * @param list<array<string, mixed>> $manual_lines
     * @return array<string, mixed> */
    public function invoicesCreateDraft(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $company_id,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        #[Schema(maxItems: 100)] array $time_entry_ids = [],
        #[Schema(maxItems: 100)] array $manual_lines = [],
        ?string $currency = null,
        ?string $due_date = null,
        ?string $notes = null,
        ?string $scheduled_issue_date = null,
        #[Schema(maxLength: 80)] ?string $invoice_number = null,
        #[Schema(format: 'date')] ?string $issue_date = null,
        #[Schema(format: 'date')] ?string $service_period_start = null,
        #[Schema(format: 'date')] ?string $service_period_end = null,
        ?bool $is_visible_to_client = null,
    ): array {
        // Only what was sent joins the body, so a request hashes as it would
        // over REST and a draft created before these fields existed replays.
        return $this->rest->call('invoices.create_draft', ['workspace' => $workspace_id], array_filter(compact('company_id', 'time_entry_ids', 'manual_lines', 'currency', 'due_date', 'scheduled_issue_date', 'notes', 'invoice_number', 'issue_date', 'service_period_start', 'service_period_end', 'is_visible_to_client'), static fn (mixed $value): bool => $value !== null), $idempotency_key);
    }

    /** @param list<string> $time_entry_ids
     * @param list<array<string, mixed>> $manual_lines
     * @return array<string, mixed> */
    public function invoicesUpdateDraft(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $invoice_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        #[Schema(maxItems: 100)] array $time_entry_ids,
        #[Schema(maxItems: 100)] array $manual_lines,
        RequestContext $context,
        ?string $currency = null,
        ?string $due_date = null,
        ?string $notes = null,
        ?string $scheduled_issue_date = null,
    ): array {
        $body = compact('expected_version', 'time_entry_ids', 'manual_lines');
        foreach (compact('currency', 'due_date', 'scheduled_issue_date', 'notes') as $name => $value) {
            if ($this->requestArguments->has($context, $name)) {
                $body[$name] = $value;
            }
        }

        return $this->rest->call('invoices.update_draft', ['workspace' => $workspace_id, 'invoice' => $invoice_id], $body, $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function invoicesUpdateDetails(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $invoice_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        RequestContext $context,
        ?string $due_date = null,
        ?string $notes = null,
        ?string $scheduled_issue_date = null,
    ): array {
        $body = compact('expected_version');
        foreach (compact('due_date', 'scheduled_issue_date', 'notes') as $name => $value) {
            if ($this->requestArguments->has($context, $name)) {
                $body[$name] = $value;
            }
        }

        return $this->rest->call('invoices.update_details', ['workspace' => $workspace_id, 'invoice' => $invoice_id], $body, $idempotency_key);
    }

    /** @param list<array<string, mixed>>|null $lines
     * @return array<string, mixed> */
    public function invoicesCorrect(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $invoice_id,
        #[Schema] bool $confirm,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema(minLength: 1, maxLength: 500)] string $reason,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        RequestContext $context,
        ?string $due_date = null,
        ?array $lines = null,
    ): array {
        $body = compact('expected_version', 'reason', 'confirm');
        if ($this->requestArguments->has($context, 'due_date')) {
            $body['due_date'] = $due_date;
        }
        if ($lines !== null) {
            $body['lines'] = $lines;
        }

        return $this->rest->call('invoices.correct', ['workspace' => $workspace_id, 'invoice' => $invoice_id], $body, $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function invoicesDiscardDraft(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $invoice_id, #[Schema] bool $confirm, #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema(minLength: 1, maxLength: 1000)] string $reason, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('invoices.discard_draft', ['workspace' => $workspace_id, 'invoice' => $invoice_id], compact('expected_version', 'reason', 'confirm'), $idempotency_key);
    }

    /**
     * Issue a draft, optionally recording money already received in the same transaction.
     *
     * @param  array<string, mixed>|null  $payment
     * @return array<string, mixed>
     */
    public function invoicesIssue(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $invoice_id,
        #[Schema] bool $confirm,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        ?array $payment = null,
    ): array {
        $flags = app(McpFeatureFlags::class);
        // A payment folded into issue answers to the same kill switch as the
        // payments.record tool; the route separately enforces its cutover,
        // scope and role, and refuses before anything is written.
        if ($payment !== null && ! $flags->enabledFor('payments.record', McpFeatureFlags::WRITE)) {
            throw new ToolCallException('Recording payments is not enabled for agents.');
        }

        // `payment` joins the body only when sent, so a plain issue hashes
        // exactly as it always has. The read view is invoices.get's, so it
        // answers to that tool's kill switch here and to its scope in the route.
        return $this->rest->call(
            'invoices.issue',
            ['workspace' => $workspace_id, 'invoice' => $invoice_id],
            compact('expected_version', 'confirm') + ($payment !== null ? ['payment' => $payment] : []),
            $idempotency_key,
            $flags->enabledFor('invoices.get', McpFeatureFlags::READ) ? [ReadView::PARAMETER => ReadView::READ] : [],
        );
    }

    /** @param list<string> $recipients
     * @return array<string, mixed> */
    public function invoicesSend(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $invoice_id,
        #[Schema] bool $confirm,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema(minItems: 1, maxItems: 10)] array $recipients,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        RequestContext $context,
        #[Schema(maxLength: InvoiceEmailDraft::SUBJECT_MAX_LENGTH)] ?string $subject = null,
        #[Schema(maxLength: InvoiceEmailDraft::MESSAGE_MAX_LENGTH)] ?string $message = null,
        ?bool $bcc_self = null,
    ): array {
        // The compose fields join the body only when sent, so a plain send
        // hashes exactly as it did before they existed.
        $body = compact('expected_version', 'recipients', 'confirm');
        foreach (compact('subject', 'message', 'bcc_self') as $name => $value) {
            if ($this->requestArguments->has($context, $name)) {
                $body[$name] = $value;
            }
        }

        return $this->rest->call('invoices.send', ['workspace' => $workspace_id, 'invoice' => $invoice_id], $body, $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function invoicesVoid(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $invoice_id, #[Schema] bool $confirm, #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema(minLength: 1, maxLength: 1000)] string $reason, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('invoices.void', ['workspace' => $workspace_id, 'invoice' => $invoice_id], compact('expected_version', 'reason', 'confirm'), $idempotency_key);
    }

    /** @param list<array<string, mixed>> $line_template
     * @return array<string, mixed> */
    public function billingSchedulesCreate(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $company_id,
        #[Schema(format: 'uuid')] string $client_agreement,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema(enum: ['monthly', 'quarterly', 'semi_annual', 'annual'])] string $cadence,
        #[Schema(format: 'date')] string $next_run_on,
        #[Schema(minimum: 0, maximum: 365)] int $due_days,
        #[Schema(pattern: '^[A-Z]{3}$')] string $currency,
        #[Schema(minItems: 1, maxItems: 100)] array $line_template,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        RequestContext $request,
        #[Schema(minimum: 1, maximum: 12)] ?int $anchor_month = null,
        #[Schema(minimum: 1, maximum: 31)] ?int $anchor_day = null,
        #[Schema] bool $is_active = true,
    ): array {
        $body = compact('company_id', 'client_agreement', 'expected_version', 'cadence', 'next_run_on', 'due_days', 'currency', 'line_template');
        foreach (compact('anchor_month', 'anchor_day', 'is_active') as $name => $value) {
            if ($this->requestArguments->has($request, $name)) {
                $body[$name] = $value;
            }
        }

        return $this->rest->call('billing_schedules.create', ['workspace' => $workspace_id], $body, $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function billingSchedulesGenerate(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $schedule_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema] bool $confirm,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
    ): array {
        return $this->rest->call('billing_schedules.generate', ['workspace' => $workspace_id, 'schedule' => $schedule_id], compact('expected_version', 'confirm'), $idempotency_key);
    }

    /**
     * The optional arguments the caller actually sent, so an omitted field
     * stays omitted and an explicit null still clears.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function provided(RequestContext $request, array $values): array
    {
        return array_filter($values, fn (string $name): bool => $this->requestArguments->has($request, $name), ARRAY_FILTER_USE_KEY);
    }
}
