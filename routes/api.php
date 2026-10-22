<?php

use App\Http\Controllers\Api\V1\AgentBillingAuditController;
use App\Http\Controllers\Api\V1\AgentBillingScheduleController;
use App\Http\Controllers\Api\V1\AgentCapacityLedgerController;
use App\Http\Controllers\Api\V1\AgentClientContactController;
use App\Http\Controllers\Api\V1\AgentClientController;
use App\Http\Controllers\Api\V1\AgentCollaborationController;
use App\Http\Controllers\Api\V1\AgentConnectionController;
use App\Http\Controllers\Api\V1\AgentExpenseController;
use App\Http\Controllers\Api\V1\AgentExpenseReceiptController;
use App\Http\Controllers\Api\V1\AgentExpenseScheduleController;
use App\Http\Controllers\Api\V1\AgentInvoiceMutationController;
use App\Http\Controllers\Api\V1\AgentInvoiceOperationsController;
use App\Http\Controllers\Api\V1\AgentMcpController;
use App\Http\Controllers\Api\V1\AgentPaymentController;
use App\Http\Controllers\Api\V1\AgentPaymentReminderController;
use App\Http\Controllers\Api\V1\AgentPortalAccessController;
use App\Http\Controllers\Api\V1\AgentPortalInvitationController;
use App\Http\Controllers\Api\V1\AgentProjectController;
use App\Http\Controllers\Api\V1\AgentProposalController;
use App\Http\Controllers\Api\V1\AgentReadController;
use App\Http\Controllers\Api\V1\AgentSquareController;
use App\Http\Controllers\Api\V1\AgentSquareLinkController;
use App\Http\Controllers\Api\V1\AgentSquareMirrorController;
use App\Http\Controllers\Api\V1\AgentTaskMutationController;
use App\Http\Controllers\Api\V1\AgentTaskOrganizationController;
use App\Http\Controllers\Api\V1\AgentTimeEntryMutationController;
use App\Http\Controllers\Api\V1\AgentWorkspaceMiscController;
use App\Http\Controllers\Api\V1\DeferredBuyDownController;
use App\Http\Controllers\Api\V1\FinanceReconciliationController;
use App\Http\Controllers\SquareWebhookController;
use App\Http\Middleware\AuthenticateFirstPartySession;
use App\Http\Middleware\EnsureAgentWorkspaceVisible;
use App\Http\Middleware\EnsureOperationDeployed;
use App\Http\Middleware\ExpectSelfRevokingOAuthResource;
use App\Http\Middleware\NoStoreAgentResponse;
use App\Http\Middleware\ThrottleOuterAgentRequests;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\Finance\ReconciliationTarget;
use App\Support\Square\SquareIdentifiers;
use Bherila\McpLaravelBridge\Http\McpHttpSecurityMiddleware;
use BWH\Auth\Http\Middleware\ExpectOAuthResource;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Middleware\CheckToken;

Route::post('/webhooks/square', SquareWebhookController::class)->name('svc.billing.square.webhook');

/*
 * Every agent operation is declared once in AgentOperationCatalog (#408):
 * its route name, method and path, scopes and write cutovers come from there.
 * Route::operation() binds the route and adds the registry's gate (scopes,
 * answered 401/403 with the reason); EnsureOperationDeployed answers 404 for
 * a switched-off cutover before it, without naming it (see the priority list
 * in bootstrap/app.php). A route adds only what is its own: workspace
 * concealment, parameter constraints, signed URLs.
 */
$operation = static fn (string $operationId, array $action): RoutingRoute => Route::operation($operationId, $action)
    ->middleware(EnsureOperationDeployed::class.':'.$operationId);

/**
 * A workspace the caller cannot see is not found, before anything else runs:
 * no write reaches its actions, no failure is audited in another tenant, and
 * a missing record answers as an unseen workspace does (#459). Every route
 * under /workspaces/{workspace} is bound through this, and nothing else is;
 * WorkspaceConcealmentTest holds the router to that.
 */
$workspaceOperation = static fn (string $operationId, array $action): RoutingRoute => $operation($operationId, $action)
    ->middleware(EnsureAgentWorkspaceVisible::class)
    ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));

// The REST resource (APP_URL/api/v1): only REST-bound tokens and the signed-in
// website's session. An MCP-bound token is refused here, as a REST token is at
// the MCP endpoint.
Route::prefix('v1')
    ->name('agent-api.v1.')
    ->middleware([ExpectOAuthResource::class.':rest', AuthenticateFirstPartySession::class, 'auth:api', ThrottleOuterAgentRequests::class.':60,1', NoStoreAgentResponse::class])
    ->group(function () use ($operation, $workspaceOperation): void {
        $workspaceOperation('collaboration.topics.list', [AgentCollaborationController::class, 'topicsList'])->whereUuid('client');
        $workspaceOperation('collaboration.topics.get', [AgentCollaborationController::class, 'topicsGet'])->whereUuid('client')->whereUuid('topic');
        $workspaceOperation('collaboration.topics.create', [AgentCollaborationController::class, 'topicsCreate'])->whereUuid('client');
        $workspaceOperation('collaboration.topics.update', [AgentCollaborationController::class, 'topicsUpdate'])->whereUuid('client')->whereUuid('topic');
        $workspaceOperation('collaboration.topics.delete', [AgentCollaborationController::class, 'topicsDelete'])->whereUuid('client')->whereUuid('topic');
        $workspaceOperation('collaboration.comments.list', [AgentCollaborationController::class, 'commentsList'])->whereUuid('client')->whereIn('parentType', ['task', 'topic'])->whereUuid('parentPublicId');
        $workspaceOperation('collaboration.comments.create', [AgentCollaborationController::class, 'commentsCreate'])->whereUuid('client')->whereIn('parentType', ['task', 'topic'])->whereUuid('parentPublicId');
        $workspaceOperation('collaboration.comments.update', [AgentCollaborationController::class, 'commentsUpdate'])->whereUuid('client')->whereUuid('comment');
        $workspaceOperation('collaboration.comments.delete', [AgentCollaborationController::class, 'commentsDelete'])->whereUuid('client')->whereUuid('comment');
        $operation('context.get', [AgentReadController::class, 'context']);
        $operation('connections.disconnect', [AgentConnectionController::class, 'disconnect']);
        $operation('workspaces.create', [AgentWorkspaceMiscController::class, 'create']);
        $workspaceOperation('search', [AgentWorkspaceMiscController::class, 'search']);
        $workspaceOperation('attachments.list', [AgentWorkspaceMiscController::class, 'index'])
            ->whereUuid('recordPublicId');
        $workspaceOperation('attachments.get', [AgentWorkspaceMiscController::class, 'show'])
            ->whereUuid('attachment');
        $workspaceOperation('attachments.download_url', [AgentWorkspaceMiscController::class, 'downloadUrl'])
            ->whereUuid('attachment');
        $workspaceOperation('attachments.download', [AgentWorkspaceMiscController::class, 'download'])
            ->whereUuid('attachment');
        $workspaceOperation('attachments.upload_url', [AgentWorkspaceMiscController::class, 'uploadUrl'])
            ->whereUuid('recordPublicId');
        $workspaceOperation('attachments.upload', [AgentWorkspaceMiscController::class, 'upload'])
            ->whereUuid('recordPublicId');
        $workspaceOperation('attachments.delete', [AgentWorkspaceMiscController::class, 'delete'])
            ->whereUuid('attachment');
        $workspaceOperation('operations.summary', [AgentReadController::class, 'summary']);
        $workspaceOperation('clients.list', [AgentClientController::class, 'index']);
        $workspaceOperation('clients.get', [AgentClientController::class, 'show'])
            ->whereUuid('client');
        $workspaceOperation('clients.create', [AgentClientController::class, 'store']);
        $workspaceOperation('clients.update', [AgentClientController::class, 'update'])
            ->whereUuid('client');
        $workspaceOperation('clients.archive', [AgentClientController::class, 'archive'])
            ->whereUuid('client');
        $workspaceOperation('clients.contacts.list', [AgentClientContactController::class, 'index'])->whereUuid('client');
        $workspaceOperation('clients.contacts.get', [AgentClientContactController::class, 'show'])->whereUuid('client')->whereUuid('contact');
        $workspaceOperation('clients.contacts.create', [AgentClientContactController::class, 'store'])->whereUuid('client');
        $workspaceOperation('clients.contacts.update', [AgentClientContactController::class, 'update'])->whereUuid('client')->whereUuid('contact');
        $workspaceOperation('clients.contacts.archive', [AgentClientContactController::class, 'archive'])->whereUuid('client')->whereUuid('contact');
        $workspaceOperation('clients.contacts.restore', [AgentClientContactController::class, 'restore'])->whereUuid('client')->whereUuid('contact');
        $workspaceOperation('clients.portal_access.list', [AgentPortalAccessController::class, 'index'])->whereUuid('client')->whereUuid('user');
        $workspaceOperation('clients.portal_access.grant', [AgentPortalAccessController::class, 'store'])->whereUuid('client')->whereUuid('user');
        $workspaceOperation('clients.portal_access.update', [AgentPortalAccessController::class, 'update'])->whereUuid('client')->whereUuid('user');
        $workspaceOperation('clients.portal_access.revoke', [AgentPortalAccessController::class, 'destroy'])->whereUuid('client')->whereUuid('user');
        $workspaceOperation('clients.portal_invitations.list', [AgentPortalInvitationController::class, 'index'])->whereUuid('client')->whereUuid('invitation');
        $workspaceOperation('clients.portal_invitations.create', [AgentPortalInvitationController::class, 'store'])->whereUuid('client')->whereUuid('invitation');
        $workspaceOperation('clients.portal_invitations.resend', [AgentPortalInvitationController::class, 'resend'])->whereUuid('client')->whereUuid('invitation');
        $workspaceOperation('clients.portal_invitations.revoke', [AgentPortalInvitationController::class, 'destroy'])->whereUuid('client')->whereUuid('invitation');
        $workspaceOperation('clients.restore', [AgentClientController::class, 'restore'])
            ->whereUuid('client');
        $workspaceOperation('agreements.list', [AgentClientController::class, 'agreements']);
        $workspaceOperation('agreements.get', [AgentClientController::class, 'agreement'])
            ->whereUuid('agreement');
        $workspaceOperation('agreements.create', [AgentClientController::class, 'storeAgreement'])
            ->whereUuid('client');
        $workspaceOperation('agreements.update', [AgentClientController::class, 'updateAgreement'])
            ->whereUuid('agreement');
        $workspaceOperation('agreements.activate', [AgentClientController::class, 'activate'])
            ->whereUuid('agreement');
        $workspaceOperation('agreements.terminate', [AgentClientController::class, 'terminate'])
            ->whereUuid('agreement');
        $workspaceOperation('projects.list', [AgentReadController::class, 'projects']);
        $workspaceOperation('projects.get', [AgentReadController::class, 'project'])
            ->whereUuid('project');
        $workspaceOperation('task_organization.get', [AgentTaskOrganizationController::class, 'show'])->whereUuid('project');
        foreach (['lists.create', 'lists.update', 'lists.archive', 'lists.restore', 'lists.reorder', 'tasks.create', 'tasks.move', 'tasks.reorder'] as $organizationOperation) {
            $workspaceOperation('task_organization.'.$organizationOperation, [AgentTaskOrganizationController::class, 'mutate'])
                ->whereUuid(['project', 'list', 'task'])
                ->defaults('task_organization_operation', $organizationOperation);
        }
        $workspaceOperation('tasks.list', [AgentReadController::class, 'tasks']);
        $workspaceOperation('tasks.get', [AgentReadController::class, 'task'])
            ->whereUuid('task');
        $workspaceOperation('time_entries.list', [AgentReadController::class, 'timeEntries']);
        $workspaceOperation('payments.list', [AgentPaymentController::class, 'index']);
        $workspaceOperation('payments.record', [AgentPaymentController::class, 'store']);
        $workspaceOperation('payments.correct', [AgentPaymentController::class, 'update'])
            ->whereUuid('payment');
        $workspaceOperation('payments.set_status', [AgentPaymentController::class, 'setStatus'])
            ->whereUuid('payment');
        $workspaceOperation('payments.refund', [AgentPaymentController::class, 'refund'])
            ->whereUuid('payment');
        $workspaceOperation('payments.reverse_refund', [AgentPaymentController::class, 'reverseRefund'])
            ->whereUuid(['payment', 'refund']);
        $workspaceOperation('invoices.list', [AgentReadController::class, 'invoices']);
        $workspaceOperation('invoices.get', [AgentReadController::class, 'invoice'])
            ->whereUuid('invoice');

        $workspaceOperation('proposals.list', [AgentProposalController::class, 'index']);
        $workspaceOperation('proposals.get', [AgentProposalController::class, 'show'])->whereUuid('proposal');
        $workspaceOperation('proposals.create', [AgentProposalController::class, 'store']);
        $workspaceOperation('proposals.send', [AgentProposalController::class, 'send'])->whereUuid('proposal');
        $workspaceOperation('proposals.accept', [AgentProposalController::class, 'accept'])->whereUuid('proposal');

        $workspaceOperation('expenses.list', [AgentExpenseController::class, 'index']);
        $workspaceOperation('expenses.log', [AgentExpenseController::class, 'store']);
        $workspaceOperation('expenses.update', [AgentExpenseController::class, 'update'])
            ->whereUuid('expense');
        $workspaceOperation('expenses.delete', [AgentExpenseController::class, 'destroy'])
            ->whereUuid('expense');

        $workspaceOperation('expenses.approve', [AgentExpenseController::class, 'approve'])
            ->whereUuid('expense');
        $workspaceOperation('expenses.unapprove', [AgentExpenseController::class, 'unapprove'])
            ->whereUuid('expense');
        $workspaceOperation('expenses.receipts.list', [AgentExpenseReceiptController::class, 'index'])
            ->whereUuid('expense');
        $workspaceOperation('expenses.receipts.upload_url', [AgentExpenseReceiptController::class, 'uploadUrl'])
            ->whereUuid('expense');
        $workspaceOperation('expenses.receipts.upload', [AgentExpenseReceiptController::class, 'store'])
            ->whereUuid('expense');
        $workspaceOperation('expenses.receipts.download', [AgentExpenseReceiptController::class, 'download'])
            ->whereUuid(['expense', 'receipt']);
        $workspaceOperation('expenses.receipts.content', [AgentExpenseReceiptController::class, 'content'])
            ->whereUuid(['expense', 'receipt'])
            ->middleware('signed:relative');
        $workspaceOperation('expense_schedules.list', [AgentExpenseScheduleController::class, 'index']);
        $workspaceOperation('expense_schedules.create', [AgentExpenseScheduleController::class, 'store']);
        $workspaceOperation('expense_schedules.update', [AgentExpenseScheduleController::class, 'update'])
            ->whereUuid('schedule');
        $workspaceOperation('expense_schedules.generate', [AgentExpenseScheduleController::class, 'generate'])
            ->whereUuid('schedule');

        $workspaceOperation('projects.members.list', [AgentProjectController::class, 'members'])
            ->whereUuid('project');
        $workspaceOperation('projects.create', [AgentProjectController::class, 'store']);
        $workspaceOperation('projects.update', [AgentProjectController::class, 'update'])
            ->whereUuid('project');
        $workspaceOperation('projects.archive', [AgentProjectController::class, 'archive'])
            ->whereUuid('project');
        $workspaceOperation('projects.members.update', [AgentProjectController::class, 'updateMember'])
            ->whereUuid('project');
        $workspaceOperation('tasks.create', [AgentTaskMutationController::class, 'store'])
            ->whereUuid('project');
        $workspaceOperation('tasks.update', [AgentTaskMutationController::class, 'update'])
            ->whereUuid('task');
        $workspaceOperation('time_entries.log', [AgentTimeEntryMutationController::class, 'store']);
        $workspaceOperation('time_entries.update', [AgentTimeEntryMutationController::class, 'update'])
            ->whereUuid('entry');
        $workspaceOperation('time_entries.delete', [AgentTimeEntryMutationController::class, 'destroy'])
            ->whereUuid('entry');
        $workspaceOperation('time_entries.unapprove', [AgentTimeEntryMutationController::class, 'unapprove'])
            ->whereUuid('entry');
        $workspaceOperation('time_entries.approve', [AgentTimeEntryMutationController::class, 'approve']);
        $workspaceOperation('invoices.hold_delivery', [AgentInvoiceOperationsController::class, 'hold'])
            ->whereUuid('invoice');
        $workspaceOperation('invoices.release_delivery', [AgentInvoiceOperationsController::class, 'release'])
            ->whereUuid('invoice');
        $workspaceOperation('invoices.add_time', [AgentInvoiceOperationsController::class, 'addTime'])
            ->whereUuid('invoice');
        $workspaceOperation('deferred_backlog.preview', [DeferredBuyDownController::class, 'preview'])->whereUuid('agreement');
        $workspaceOperation('invoices.create_deferred_buydown', [DeferredBuyDownController::class, 'create'])->whereUuid('agreement');
        $workspaceOperation('invoices.generate_period', [AgentInvoiceOperationsController::class, 'generate'])
            ->whereUuid('agreement');
        $workspaceOperation('invoices.preview_period', [AgentInvoiceOperationsController::class, 'preview'])
            ->whereUuid('agreement');
        $workspaceOperation('invoices.pdf', [AgentInvoiceOperationsController::class, 'pdfLink'])
            ->whereUuid('invoice');
        $workspaceOperation('invoices.download_pdf', [AgentInvoiceOperationsController::class, 'pdf'])
            ->whereUuid('invoice');
        // The aggregate billing audits (#459), which MCP reaches through these routes.
        foreach ([
            'billing.audit_unplaceable_invoices' => 'unplaceableInvoices',
            'billing.audit_undated_collectible_invoices' => 'undatedCollectibleInvoices',
            'billing.audit_missing_billed_overage' => 'missingBilledOverage',
            'billing.audit_opening_rollover' => 'openingRollover',
            'billing.preflight_schedule_generation' => 'scheduleGenerationPreflight',
            'billing.audit_unlinked_proposal_agreements' => 'unlinkedProposalAgreements',
        ] as $operationId => $action) {
            $workspaceOperation($operationId, [AgentBillingAuditController::class, $action]);
        }
        $workspaceOperation('billing_audit.stale_and_missing', [AgentInvoiceOperationsController::class, 'audit']);
        // svc's read-only mirror of Square (#438), refreshed by the scheduled
        // sync or on request; reads of it never reach Square.
        foreach ([
            'invoices.square_candidates' => 'candidates',
            'square.customers.unlinked' => 'unlinkedCustomers',
            'square.import_customer' => 'importCustomer',
            'square.sync' => 'sync',
            'invoices.square_reconcile' => 'reconcileInvoice',
            'square.mirror.invoices.list' => 'invoices',
            'square.series.list' => 'series',
            'square.series.get' => 'seriesDetail',
        ] as $operationId => $action) {
            $workspaceOperation($operationId, [AgentSquareMirrorController::class, $action])
                ->where('series', '[0-9]{6}')
                ->whereUuid('invoice')
                ->where('customer', '[A-Za-z0-9][A-Za-z0-9:_\-]{0,191}');
        }
        // Read-only Square pass-through (#438). Square ids are opaque; the
        // static `/search` paths are matched before the id routes beside them.
        foreach ([
            'square.locations.list' => ['locations', null],
            'square.customers.list' => ['customers', null],
            'square.customers.search' => ['searchCustomers', null],
            'square.customers.get' => ['customer', 'customer'],
            'square.invoices.list' => ['invoices', null],
            'square.invoices.search' => ['searchInvoices', null],
            'square.invoices.get' => ['invoice', 'invoice'],
            'square.orders.get' => ['order', 'order'],
            'square.payments.list' => ['payments', null],
            'square.payments.get' => ['payment', 'payment'],
        ] as $operationId => [$action, $id]) {
            $route = $workspaceOperation($operationId, [AgentSquareController::class, $action]);
            if ($id !== null) {
                $route->where($id, $id === 'invoice'
                    ? SquareIdentifiers::INVOICE_ID_ROUTE_PATTERN
                    : '[A-Za-z0-9][A-Za-z0-9:_\-]{0,191}');
            }
        }
        $workspaceOperation('billing_schedules.list', [AgentBillingScheduleController::class, 'index']);
        $workspaceOperation('billing_schedules.get', [AgentBillingScheduleController::class, 'show'])
            ->whereUuid('schedule');
        $workspaceOperation('billing_schedules.create', [AgentBillingScheduleController::class, 'store']);
        $workspaceOperation('billing_schedules.generate', [AgentBillingScheduleController::class, 'generate'])
            ->whereUuid('schedule');
        $workspaceOperation('capacity_ledger.get', [AgentCapacityLedgerController::class, 'show'])
            ->whereUuid('agreement');
        $workspaceOperation('invoices.create_draft', [AgentInvoiceMutationController::class, 'createDraft']);
        $workspaceOperation('invoices.update_draft', [AgentInvoiceMutationController::class, 'updateDraft'])
            ->whereUuid('invoice');
        $workspaceOperation('invoices.update_details', [AgentInvoiceMutationController::class, 'updateDetails'])
            ->whereUuid('invoice');
        $workspaceOperation('invoices.corrections.get', [AgentInvoiceMutationController::class, 'correction'])
            ->whereUuid('invoice')->whereUuid('correction');
        $workspaceOperation('invoices.correct', [AgentInvoiceMutationController::class, 'correct'])
            ->whereUuid('invoice');
        $workspaceOperation('invoices.discard_draft', [AgentInvoiceMutationController::class, 'discardDraft'])
            ->whereUuid('invoice');
        $workspaceOperation('invoices.issue', [AgentInvoiceMutationController::class, 'issue'])
            ->whereUuid('invoice');
        $workspaceOperation('invoices.send', [AgentInvoiceMutationController::class, 'send'])
            ->whereUuid('invoice');
        $workspaceOperation('invoices.void', [AgentInvoiceMutationController::class, 'void'])
            ->whereUuid('invoice');

        // SVC's side of Square (#438): links, the client's customer and the
        // billed-in-Square marker. Nothing behind these routes calls Square.
        $workspaceOperation('invoices.square_link', [AgentSquareLinkController::class, 'link'])->whereUuid('invoice');
        $workspaceOperation('invoices.square_unlink', [AgentSquareLinkController::class, 'unlink'])->whereUuid('invoice');
        $workspaceOperation('invoices.square_cover', [AgentSquareLinkController::class, 'cover'])->whereUuid('invoice');
        $workspaceOperation('invoices.square_uncover', [AgentSquareLinkController::class, 'uncover'])->whereUuid('invoice');
        $workspaceOperation('companies.square_customer.get', [AgentSquareLinkController::class, 'customer'])->whereUuid('client');
        $workspaceOperation('companies.square_customer.set', [AgentSquareLinkController::class, 'setCustomer'])->whereUuid('client');
        $workspaceOperation('companies.square_customer.clear', [AgentSquareLinkController::class, 'clearCustomer'])->whereUuid('client');
        $workspaceOperation('agreements.square_series.set', [AgentSquareLinkController::class, 'markAgreement'])->whereUuid('agreement');
        // Payment reminders (#438): sent by hand here, or by the per-client cadence.
        foreach ([
            'invoices.reminders.list' => ['index', 'invoice'],
            'invoices.send_reminder' => ['send', 'invoice'],
            'companies.payment_reminders.get' => ['company', 'client'],
            'companies.payment_reminders.set' => ['setCompany', 'client'],
        ] as $operationId => [$action, $id]) {
            $workspaceOperation($operationId, [AgentPaymentReminderController::class, $action])->whereUuid($id);
        }
        $workspaceOperation('agreements.square_series.clear', [AgentSquareLinkController::class, 'clearAgreement'])->whereUuid('agreement');
    });

// The finance reconciliation operations (#457): the REST audience's OAuth
// tokens (finance:read, finance:reconcile), and the Sanctum token the finance
// integration has always used, whose finance.read and finance.reconcile
// abilities the route gate reads as those scopes (AgentPrincipalResolver).
// Sanctum is tried first, so its tokens never reach Passport's validator. Not
// the website's session: nothing in the browser reconciles.
Route::prefix('v1')
    ->name('agent-api.v1.')
    ->middleware([ExpectOAuthResource::class.':rest', 'auth:sanctum,api', ThrottleOuterAgentRequests::class.':60,1', NoStoreAgentResponse::class])
    ->group(function () use ($workspaceOperation): void {
        // A workspace the caller cannot see answers 404 before anything else,
        // as on every other workspace route: nothing - not even a failed
        // write's audit - reaches a tenant the caller is not in.
        foreach (['finance.invoice_payments.list' => 'index', 'finance.payouts.list' => 'payouts', 'finance.processor_movements.list' => 'movements'] as $operationId => $action) {
            $workspaceOperation($operationId, [FinanceReconciliationController::class, $action]);
        }
        foreach (['finance.reconciliations.upsert' => 'upsert', 'finance.reconciliations.deactivate' => 'destroy', 'finance.reconciliations.acknowledge' => 'acknowledge'] as $operationId => $action) {
            $workspaceOperation($operationId, [FinanceReconciliationController::class, $action])
                ->whereUuid('payment')
                ->where('external_system', ReconciliationTarget::SYSTEM_PATTERN)
                ->whereUuid('transaction');
        }
    });

// Credential disposal works for either OAuth resource and every scope set.
// No website-session or Sanctum fallback: the bearer itself is the target.
Route::prefix('v1')
    ->name('agent-api.v1.')
    ->middleware([ExpectSelfRevokingOAuthResource::class, 'auth:api', 'throttle:60,1', NoStoreAgentResponse::class])
    ->group(function () use ($operation): void {
        $operation('oauth.token.revoke', [AgentConnectionController::class, 'revokeToken']);
    });

// Disconnecting takes `mcp:use`, which only the MCP resource admits, so the
// credential that can disconnect is an MCP connection's own: this route
// belongs to the MCP audience, not REST's. The session holds no `mcp:use`.
Route::prefix('v1')
    ->name('agent-api.v1.')
    ->middleware([ExpectOAuthResource::class.':mcp', 'auth:api', 'throttle:60,1', NoStoreAgentResponse::class])
    ->group(function () use ($operation): void {
        $operation('connections.revoke', [AgentConnectionController::class, 'destroy']);
    });

Route::options('/v1/mcp', static fn () => response()->noContent())
    ->middleware([McpHttpSecurityMiddleware::class, 'throttle:60,1'])
    ->name('agent-api.v1.mcp.options');
Route::match(['POST', 'DELETE'], '/v1/mcp', AgentMcpController::class)
    ->middleware([McpHttpSecurityMiddleware::class, ExpectOAuthResource::class.':mcp', 'auth:api', CheckToken::using(AgentApiScopes::MCP_USE), 'throttle:60,1', NoStoreAgentResponse::class])
    ->name('agent-api.v1.mcp');
