<?php

use App\Http\Controllers\Api\V1\AgentBillingScheduleController;
use App\Http\Controllers\Api\V1\AgentClientController;
use App\Http\Controllers\Api\V1\AgentConnectionController;
use App\Http\Controllers\Api\V1\AgentExpenseController;
use App\Http\Controllers\Api\V1\AgentExpenseReceiptController;
use App\Http\Controllers\Api\V1\AgentExpenseScheduleController;
use App\Http\Controllers\Api\V1\AgentInvoiceMutationController;
use App\Http\Controllers\Api\V1\AgentInvoiceOperationsController;
use App\Http\Controllers\Api\V1\AgentMcpController;
use App\Http\Controllers\Api\V1\AgentPaymentController;
use App\Http\Controllers\Api\V1\AgentProjectController;
use App\Http\Controllers\Api\V1\AgentProposalController;
use App\Http\Controllers\Api\V1\AgentReadController;
use App\Http\Controllers\Api\V1\AgentTaskMutationController;
use App\Http\Controllers\Api\V1\AgentTimeEntryMutationController;
use App\Http\Controllers\Api\V1\AgentWorkspaceMiscController;
use App\Http\Controllers\Api\V1\InvoicePaymentController;
use App\Http\Controllers\Api\V1\PaymentReconciliationController;
use App\Http\Middleware\AuthenticateFirstPartySession;
use App\Http\Middleware\EnsureAgentWorkspaceVisible;
use App\Http\Middleware\EnsureOperationDeployed;
use App\Http\Middleware\NoStoreAgentResponse;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiScopes;
use Bherila\McpLaravelBridge\Http\McpHttpSecurityMiddleware;
use BWH\Auth\Http\Middleware\ExpectOAuthResource;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Middleware\CheckToken;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

Route::middleware(['auth:sanctum', 'throttle:60,1'])
    ->prefix('v1/workspaces/{workspace}')
    ->group(function (): void {
        Route::get('/invoice-payments', InvoicePaymentController::class)
            ->middleware(CheckAbilities::class.':finance.read')
            ->name('api.v1.invoice-payments.index');

        Route::put(
            '/invoice-payments/{clientInvoicePayment}/reconciliations/{externalSystemSlug}/{externalTransactionUuid}',
            [PaymentReconciliationController::class, 'upsert'],
        )
            ->where('externalSystemSlug', '[a-z0-9]+(?:-[a-z0-9]+)*')
            ->whereUuid('externalTransactionUuid')
            ->middleware(CheckAbilities::class.':finance.reconcile')
            ->name('api.v1.payment-reconciliations.upsert');

        Route::delete(
            '/invoice-payments/{clientInvoicePayment}/reconciliations/{externalSystemSlug}/{externalTransactionUuid}',
            [PaymentReconciliationController::class, 'destroy'],
        )
            ->where('externalSystemSlug', '[a-z0-9]+(?:-[a-z0-9]+)*')
            ->whereUuid('externalTransactionUuid')
            ->middleware(CheckAbilities::class.':finance.reconcile')
            ->name('api.v1.payment-reconciliations.destroy');
    });

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

Route::prefix('v1')
    ->name('agent-api.v1.')
    ->middleware([ExpectOAuthResource::class, AuthenticateFirstPartySession::class, 'auth:api', 'throttle:60,1', NoStoreAgentResponse::class])
    ->group(function () use ($operation): void {
        $operation('context.get', [AgentReadController::class, 'context']);
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
        $operation('connections.revoke', [AgentConnectionController::class, 'destroy']);
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
        $operation('invoices.list', [AgentReadController::class, 'invoices']);
        $operation('invoices.get', [AgentReadController::class, 'invoice'])
            ->whereUuid('invoice');

        $operation('proposals.list', [AgentProposalController::class, 'index'])->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('proposals.get', [AgentProposalController::class, 'show'])->whereUuid('proposal')->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('proposals.create', [AgentProposalController::class, 'store'])->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('proposals.send', [AgentProposalController::class, 'send'])->whereUuid('proposal')->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('proposals.accept', [AgentProposalController::class, 'accept'])->whereUuid('proposal')->middleware(EnsureAgentWorkspaceVisible::class)->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));

        $operation('expenses.list', [AgentExpenseController::class, 'index']);
        $operation('expenses.log', [AgentExpenseController::class, 'store']);
        $operation('expenses.update', [AgentExpenseController::class, 'update'])
            ->whereUuid('expense');
        $operation('expenses.delete', [AgentExpenseController::class, 'destroy'])
            ->whereUuid('expense');

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
        $operation('tasks.create', [AgentTaskMutationController::class, 'store'])
            ->whereUuid('project');
        $operation('tasks.update', [AgentTaskMutationController::class, 'update'])
            ->whereUuid('task');
        $operation('time_entries.log', [AgentTimeEntryMutationController::class, 'store']);
        $operation('time_entries.update', [AgentTimeEntryMutationController::class, 'update'])
            ->whereUuid('entry');
        $operation('time_entries.delete', [AgentTimeEntryMutationController::class, 'destroy'])
            ->whereUuid('entry');
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
        $operation('invoices.pdf', [AgentInvoiceOperationsController::class, 'pdfLink'])
            ->whereUuid('invoice')
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('invoices.download_pdf', [AgentInvoiceOperationsController::class, 'pdf'])
            ->whereUuid('invoice')
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
        $operation('billing_audit.stale_and_missing', [AgentInvoiceOperationsController::class, 'audit'])
            ->middleware(EnsureAgentWorkspaceVisible::class)
            ->missing(static fn () => abort(404, (new ModelNotFoundException)->setModel(Workspace::class)->getMessage(), ['Cache-Control' => 'private, no-store']));
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
        $operation('invoices.create_draft', [AgentInvoiceMutationController::class, 'createDraft']);
        $operation('invoices.update_draft', [AgentInvoiceMutationController::class, 'updateDraft'])
            ->whereUuid('invoice');
        $operation('invoices.update_details', [AgentInvoiceMutationController::class, 'updateDetails'])
            ->whereUuid('invoice');
        $operation('invoices.correct', [AgentInvoiceMutationController::class, 'correct'])
            ->whereUuid('invoice');
        $operation('invoices.discard_draft', [AgentInvoiceMutationController::class, 'discardDraft'])
            ->whereUuid('invoice');
        $operation('invoices.issue', [AgentInvoiceMutationController::class, 'issue'])
            ->whereUuid('invoice');
        $operation('invoices.send', [AgentInvoiceMutationController::class, 'send'])
            ->whereUuid('invoice');
        $operation('invoices.void', [AgentInvoiceMutationController::class, 'void'])
            ->whereUuid('invoice');
    });

Route::options('/v1/mcp', static fn () => response()->noContent())
    ->middleware([McpHttpSecurityMiddleware::class, 'throttle:60,1'])
    ->name('agent-api.v1.mcp.options');
Route::match(['POST', 'DELETE'], '/v1/mcp', AgentMcpController::class)
    ->middleware([McpHttpSecurityMiddleware::class, ExpectOAuthResource::class, 'auth:api', CheckToken::using(AgentApiScopes::MCP_USE), 'throttle:60,1', NoStoreAgentResponse::class])
    ->name('agent-api.v1.mcp');
