<?php

use App\Http\Controllers\Api\V1\AgentBillingAuditController;
use App\Http\Controllers\Api\V1\AgentBillingScheduleController;
use App\Http\Controllers\Api\V1\AgentCapacityLedgerController;
use App\Http\Controllers\Api\V1\AgentClientController;
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
use App\Http\Controllers\Api\V1\AgentTimeEntryMutationController;
use App\Http\Controllers\Api\V1\AgentWorkspaceMiscController;
use App\Http\Controllers\Api\V1\FinanceReconciliationController;
use App\Http\Controllers\SquareWebhookController;
use App\Http\Middleware\AuthenticateFirstPartySession;
use App\Http\Middleware\EnsureAgentWorkspaceVisible;
use App\Http\Middleware\EnsureOperationDeployed;
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
 * a missing record answers as an unseen workspace does (#459).
 */
$inVisibleWorkspace = static fn (RoutingRoute $route): RoutingRoute => $route
    ->middleware(EnsureAgentWorkspaceVisible::class)
    ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));

// The REST resource (APP_URL/api/v1): only REST-bound tokens and the signed-in
// website's session. An MCP-bound token is refused here, as a REST token is at
// the MCP endpoint.
Route::prefix('v1')
    ->name('agent-api.v1.')
    ->middleware([ExpectOAuthResource::class.':rest', AuthenticateFirstPartySession::class, 'auth:api', ThrottleOuterAgentRequests::class.':60,1', NoStoreAgentResponse::class])
    ->group(function () use ($operation, $inVisibleWorkspace): void {
        $operation('context.get', [AgentReadController::class, 'context']);
        $operation('connections.disconnect', [AgentConnectionController::class, 'disconnect']);
        $operation('workspaces.create', [AgentWorkspaceMiscController::class, 'create']);
        $operation('search', [AgentWorkspaceMiscController::class, 'search'])
            ->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('attachments.list', [AgentWorkspaceMiscController::class, 'index'])
            ->whereUuid('recordPublicId')->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('attachments.get', [AgentWorkspaceMiscController::class, 'show'])
            ->whereUuid('attachment')->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('attachments.download_url', [AgentWorkspaceMiscController::class, 'downloadUrl'])
            ->whereUuid('attachment')->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('attachments.download', [AgentWorkspaceMiscController::class, 'download'])
            ->whereUuid('attachment')->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('attachments.upload_url', [AgentWorkspaceMiscController::class, 'uploadUrl'])
            ->whereUuid('recordPublicId')->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('attachments.upload', [AgentWorkspaceMiscController::class, 'upload'])
            ->whereUuid('recordPublicId')->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('attachments.delete', [AgentWorkspaceMiscController::class, 'delete'])
            ->whereUuid('attachment')->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('operations.summary', [AgentReadController::class, 'summary']);
        $operation('clients.list', [AgentClientController::class, 'index'])
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('clients.get', [AgentClientController::class, 'show'])
            ->whereUuid('client')
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('clients.create', [AgentClientController::class, 'store'])
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('clients.update', [AgentClientController::class, 'update'])
            ->whereUuid('client')
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('clients.archive', [AgentClientController::class, 'archive'])
            ->whereUuid('client')
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('clients.portal_access.list', [AgentPortalAccessController::class, 'index'])->whereUuid('client')->whereUuid('user')->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('clients.portal_access.grant', [AgentPortalAccessController::class, 'store'])->whereUuid('client')->whereUuid('user')->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('clients.portal_access.update', [AgentPortalAccessController::class, 'update'])->whereUuid('client')->whereUuid('user')->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('clients.portal_access.revoke', [AgentPortalAccessController::class, 'destroy'])->whereUuid('client')->whereUuid('user')->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('clients.portal_invitations.list', [AgentPortalInvitationController::class, 'index'])->whereUuid('client')->whereUuid('invitation')->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('clients.portal_invitations.create', [AgentPortalInvitationController::class, 'store'])->whereUuid('client')->whereUuid('invitation')->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('clients.portal_invitations.resend', [AgentPortalInvitationController::class, 'resend'])->whereUuid('client')->whereUuid('invitation')->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('clients.portal_invitations.revoke', [AgentPortalInvitationController::class, 'destroy'])->whereUuid('client')->whereUuid('invitation')->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('clients.restore', [AgentClientController::class, 'restore'])
            ->whereUuid('client')
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('agreements.list', [AgentClientController::class, 'agreements'])
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('agreements.get', [AgentClientController::class, 'agreement'])
            ->whereUuid('agreement')
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('agreements.create', [AgentClientController::class, 'storeAgreement'])
            ->whereUuid('client')
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('agreements.update', [AgentClientController::class, 'updateAgreement'])
            ->whereUuid('agreement')
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('agreements.activate', [AgentClientController::class, 'activate'])
            ->whereUuid('agreement')
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('agreements.terminate', [AgentClientController::class, 'terminate'])
            ->whereUuid('agreement')
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('projects.list', [AgentReadController::class, 'projects']);
        $operation('projects.get', [AgentReadController::class, 'project'])
            ->whereUuid('project');
        $operation('tasks.list', [AgentReadController::class, 'tasks']);
        $operation('tasks.get', [AgentReadController::class, 'task'])
            ->whereUuid('task');
        $operation('time_entries.list', [AgentReadController::class, 'timeEntries']);
        $operation('payments.list', [AgentPaymentController::class, 'index'])
            // Binding failures bypass NoStoreAgentResponse; conceal them just
            // like an inaccessible workspace found by the payment read service.
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('payments.record', [AgentPaymentController::class, 'store']);
        $operation('payments.correct', [AgentPaymentController::class, 'update'])
            ->whereUuid('payment');
        $operation('payments.set_status', [AgentPaymentController::class, 'setStatus'])
            ->whereUuid('payment');
        $operation('payments.refund', [AgentPaymentController::class, 'refund'])
            ->whereUuid('payment');
        $operation('payments.reverse_refund', [AgentPaymentController::class, 'reverseRefund'])
            ->whereUuid(['payment', 'refund']);
        $operation('invoices.list', [AgentReadController::class, 'invoices']);
        $operation('invoices.get', [AgentReadController::class, 'invoice'])
            ->whereUuid('invoice');

        $operation('proposals.list', [AgentProposalController::class, 'index'])->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('proposals.get', [AgentProposalController::class, 'show'])->whereUuid('proposal')->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('proposals.create', [AgentProposalController::class, 'store'])->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('proposals.send', [AgentProposalController::class, 'send'])->whereUuid('proposal')->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('proposals.accept', [AgentProposalController::class, 'accept'])->whereUuid('proposal')->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));

        $operation('expenses.list', [AgentExpenseController::class, 'index']);
        $inVisibleWorkspace($operation('expenses.log', [AgentExpenseController::class, 'store']));
        $inVisibleWorkspace($operation('expenses.update', [AgentExpenseController::class, 'update'])
            ->whereUuid('expense'));
        $inVisibleWorkspace($operation('expenses.delete', [AgentExpenseController::class, 'destroy'])
            ->whereUuid('expense'));

        $operation('expenses.approve', [AgentExpenseController::class, 'approve'])
            ->whereUuid('expense')
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('expenses.unapprove', [AgentExpenseController::class, 'unapprove'])
            ->whereUuid('expense')
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('expenses.receipts.list', [AgentExpenseReceiptController::class, 'index'])
            ->whereUuid('expense')
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('expenses.receipts.upload_url', [AgentExpenseReceiptController::class, 'uploadUrl'])
            ->whereUuid('expense')
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('expenses.receipts.upload', [AgentExpenseReceiptController::class, 'store'])
            ->whereUuid('expense')
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('expenses.receipts.download', [AgentExpenseReceiptController::class, 'download'])
            ->whereUuid(['expense', 'receipt'])
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('expenses.receipts.content', [AgentExpenseReceiptController::class, 'content'])
            ->whereUuid(['expense', 'receipt'])
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->middleware('signed:relative')
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('expense_schedules.list', [AgentExpenseScheduleController::class, 'index'])
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('expense_schedules.create', [AgentExpenseScheduleController::class, 'store'])
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('expense_schedules.update', [AgentExpenseScheduleController::class, 'update'])
            ->whereUuid('schedule')
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('expense_schedules.generate', [AgentExpenseScheduleController::class, 'generate'])
            ->whereUuid('schedule')
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));

        $operation('projects.members.list', [AgentProjectController::class, 'members'])
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']))
            ->whereUuid('project')->middleware(EnsureAgentWorkspaceVisible::class);
        $operation('projects.create', [AgentProjectController::class, 'store'])
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']))
            ->middleware(EnsureAgentWorkspaceVisible::class);
        $operation('projects.update', [AgentProjectController::class, 'update'])
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']))
            ->whereUuid('project')->middleware(EnsureAgentWorkspaceVisible::class);
        $operation('projects.archive', [AgentProjectController::class, 'archive'])
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']))
            ->whereUuid('project')->middleware(EnsureAgentWorkspaceVisible::class);
        $operation('projects.members.update', [AgentProjectController::class, 'updateMember'])
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']))
            ->whereUuid('project')->middleware(EnsureAgentWorkspaceVisible::class);
        $inVisibleWorkspace($operation('tasks.create', [AgentTaskMutationController::class, 'store'])
            ->whereUuid('project'));
        $inVisibleWorkspace($operation('tasks.update', [AgentTaskMutationController::class, 'update'])
            ->whereUuid('task'));
        $inVisibleWorkspace($operation('time_entries.log', [AgentTimeEntryMutationController::class, 'store']));
        $inVisibleWorkspace($operation('time_entries.update', [AgentTimeEntryMutationController::class, 'update'])
            ->whereUuid('entry'));
        $inVisibleWorkspace($operation('time_entries.delete', [AgentTimeEntryMutationController::class, 'destroy'])
            ->whereUuid('entry'));
        $operation('time_entries.unapprove', [AgentTimeEntryMutationController::class, 'unapprove'])
            ->whereUuid('entry')->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('time_entries.approve', [AgentTimeEntryMutationController::class, 'approve']);
        $operation('invoices.hold_delivery', [AgentInvoiceOperationsController::class, 'hold'])
            ->whereUuid('invoice')
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('invoices.release_delivery', [AgentInvoiceOperationsController::class, 'release'])
            ->whereUuid('invoice')
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('invoices.add_time', [AgentInvoiceOperationsController::class, 'addTime'])
            ->whereUuid('invoice')
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('invoices.generate_period', [AgentInvoiceOperationsController::class, 'generate'])
            ->whereUuid('agreement')
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('invoices.preview_period', [AgentInvoiceOperationsController::class, 'preview'])
            ->whereUuid('agreement')
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('invoices.pdf', [AgentInvoiceOperationsController::class, 'pdfLink'])
            ->whereUuid('invoice')
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('invoices.download_pdf', [AgentInvoiceOperationsController::class, 'pdf'])
            ->whereUuid('invoice')
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        // The aggregate billing audits (#459), which MCP reaches through these routes.
        foreach ([
            'billing.audit_unplaceable_invoices' => 'unplaceableInvoices',
            'billing.audit_undated_collectible_invoices' => 'undatedCollectibleInvoices',
            'billing.audit_missing_billed_overage' => 'missingBilledOverage',
            'billing.audit_opening_rollover' => 'openingRollover',
            'billing.preflight_schedule_generation' => 'scheduleGenerationPreflight',
            'billing.audit_unlinked_proposal_agreements' => 'unlinkedProposalAgreements',
        ] as $operationId => $action) {
            $inVisibleWorkspace($operation($operationId, [AgentBillingAuditController::class, $action]));
        }
        $operation('billing_audit.stale_and_missing', [AgentInvoiceOperationsController::class, 'audit'])
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
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
            $operation($operationId, [AgentSquareMirrorController::class, $action])
                ->where('series', '[0-9]{6}')
                ->whereUuid('invoice')
                ->where('customer', '[A-Za-z0-9][A-Za-z0-9:_\-]{0,191}')
                ->middleware(EnsureAgentWorkspaceVisible::class)
                ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
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
            $route = $operation($operationId, [AgentSquareController::class, $action])
                ->middleware(EnsureAgentWorkspaceVisible::class)
                ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
            if ($id !== null) {
                $route->where($id, $id === 'invoice'
                    ? SquareIdentifiers::INVOICE_ID_ROUTE_PATTERN
                    : '[A-Za-z0-9][A-Za-z0-9:_\-]{0,191}');
            }
        }
        $operation('billing_schedules.list', [AgentBillingScheduleController::class, 'index'])
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('billing_schedules.get', [AgentBillingScheduleController::class, 'show'])
            ->whereUuid('schedule')
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('billing_schedules.create', [AgentBillingScheduleController::class, 'store'])
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('billing_schedules.generate', [AgentBillingScheduleController::class, 'generate'])
            ->whereUuid('schedule')
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('capacity_ledger.get', [AgentCapacityLedgerController::class, 'show'])
            ->whereUuid('agreement')
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('invoices.create_draft', [AgentInvoiceMutationController::class, 'createDraft']);
        $operation('invoices.update_draft', [AgentInvoiceMutationController::class, 'updateDraft'])
            ->whereUuid('invoice');
        $operation('invoices.update_details', [AgentInvoiceMutationController::class, 'updateDetails'])
            ->whereUuid('invoice');
        $operation('invoices.corrections.get', [AgentInvoiceMutationController::class, 'correction'])
            ->whereUuid('invoice')->whereUuid('correction')->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('invoices.correct', [AgentInvoiceMutationController::class, 'correct'])
            ->whereUuid('invoice');
        $operation('invoices.discard_draft', [AgentInvoiceMutationController::class, 'discardDraft'])
            ->whereUuid('invoice');
        $inVisibleWorkspace($operation('invoices.issue', [AgentInvoiceMutationController::class, 'issue'])
            ->whereUuid('invoice'));
        $operation('invoices.send', [AgentInvoiceMutationController::class, 'send'])
            ->whereUuid('invoice');
        $operation('invoices.void', [AgentInvoiceMutationController::class, 'void'])
            ->whereUuid('invoice');

        // SVC's side of Square (#438): links, the client's customer and the
        // billed-in-Square marker. Nothing behind these routes calls Square.
        $operation('invoices.square_link', [AgentSquareLinkController::class, 'link'])->whereUuid('invoice')->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('invoices.square_unlink', [AgentSquareLinkController::class, 'unlink'])->whereUuid('invoice')->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('invoices.square_cover', [AgentSquareLinkController::class, 'cover'])->whereUuid('invoice')->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('invoices.square_uncover', [AgentSquareLinkController::class, 'uncover'])->whereUuid('invoice')->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('companies.square_customer.get', [AgentSquareLinkController::class, 'customer'])->whereUuid('client')->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('companies.square_customer.set', [AgentSquareLinkController::class, 'setCustomer'])->whereUuid('client')->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('companies.square_customer.clear', [AgentSquareLinkController::class, 'clearCustomer'])->whereUuid('client')->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('agreements.square_series.set', [AgentSquareLinkController::class, 'markAgreement'])->whereUuid('agreement')->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        // Payment reminders (#438): sent by hand here, or by the per-client cadence.
        foreach ([
            'invoices.reminders.list' => ['index', 'invoice'],
            'invoices.send_reminder' => ['send', 'invoice'],
            'companies.payment_reminders.get' => ['company', 'client'],
            'companies.payment_reminders.set' => ['setCompany', 'client'],
        ] as $operationId => [$action, $id]) {
            $operation($operationId, [AgentPaymentReminderController::class, $action])->whereUuid($id)->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        }
        $operation('agreements.square_series.clear', [AgentSquareLinkController::class, 'clearAgreement'])->whereUuid('agreement')->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
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
    ->group(function () use ($operation): void {
        // A workspace the caller cannot see answers 404 before anything else,
        // as on every other workspace route: nothing - not even a failed
        // write's audit - reaches a tenant the caller is not in.
        foreach (['finance.invoice_payments.list' => 'index', 'finance.payouts.list' => 'payouts'] as $operationId => $action) {
            $operation($operationId, [FinanceReconciliationController::class, $action])
                ->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        }
        foreach (['finance.reconciliations.upsert' => 'upsert', 'finance.reconciliations.deactivate' => 'destroy'] as $operationId => $action) {
            $operation($operationId, [FinanceReconciliationController::class, $action])
                ->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']))
                ->whereUuid('payment')
                ->where('external_system', ReconciliationTarget::SYSTEM_PATTERN)
                ->whereUuid('transaction');
        }
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
