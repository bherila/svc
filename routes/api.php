<?php

use App\Http\Controllers\Api\V1\AgentInvoiceMutationController;
use App\Http\Controllers\Api\V1\AgentMcpController;
use App\Http\Controllers\Api\V1\AgentReadController;
use App\Http\Controllers\Api\V1\AgentTaskMutationController;
use App\Http\Controllers\Api\V1\AgentTimeEntryMutationController;
use App\Http\Controllers\Api\V1\InvoicePaymentController;
use App\Http\Controllers\Api\V1\PaymentReconciliationController;
use App\Http\Middleware\EnsureAgentWritesEnabled;
use App\Http\Middleware\NoStoreAgentResponse;
use App\Support\AgentApi\AgentApiScopes;
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

Route::prefix('v1')
    ->name('agent-api.v1.')
    ->middleware(['auth:api', 'throttle:60,1', NoStoreAgentResponse::class])
    ->group(function (): void {
        Route::get('/context', [AgentReadController::class, 'context'])
            ->middleware(CheckToken::using(AgentApiScopes::IDENTITY_READ))
            ->name('context');
        Route::get('/workspaces/{workspace}/summary', [AgentReadController::class, 'summary'])
            ->middleware(CheckToken::using(AgentApiScopes::IDENTITY_READ))
            ->name('workspaces.summary');
        Route::get('/workspaces/{workspace}/projects', [AgentReadController::class, 'projects'])
            ->middleware(CheckToken::using(AgentApiScopes::PROJECTS_READ))
            ->name('projects.index');
        Route::get('/workspaces/{workspace}/projects/{project}', [AgentReadController::class, 'project'])
            ->whereUuid('project')
            ->middleware(CheckToken::using(AgentApiScopes::PROJECTS_READ))
            ->name('projects.show');
        Route::get('/workspaces/{workspace}/tasks', [AgentReadController::class, 'tasks'])
            ->middleware(CheckToken::using(AgentApiScopes::TASKS_READ))
            ->name('tasks.index');
        Route::get('/workspaces/{workspace}/tasks/{task}', [AgentReadController::class, 'task'])
            ->whereUuid('task')
            ->middleware(CheckToken::using(AgentApiScopes::TASKS_READ))
            ->name('tasks.show');
        Route::get('/workspaces/{workspace}/time-entries', [AgentReadController::class, 'timeEntries'])
            ->middleware(CheckToken::using(AgentApiScopes::TIME_READ))
            ->name('time-entries.index');
        Route::get('/workspaces/{workspace}/invoices', [AgentReadController::class, 'invoices'])
            ->middleware(CheckToken::using(AgentApiScopes::BILLING_READ))
            ->name('invoices.index');
        Route::get('/workspaces/{workspace}/invoices/{invoice}', [AgentReadController::class, 'invoice'])
            ->whereUuid('invoice')
            ->middleware(CheckToken::using(AgentApiScopes::BILLING_READ))
            ->name('invoices.show');

        Route::post('/workspaces/{workspace}/projects/{project}/tasks', [AgentTaskMutationController::class, 'store'])
            ->whereUuid('project')->middleware([CheckToken::using(AgentApiScopes::TASKS_WRITE), EnsureAgentWritesEnabled::class])->name('tasks.store');
        Route::patch('/workspaces/{workspace}/tasks/{task}', [AgentTaskMutationController::class, 'update'])
            ->whereUuid('task')->middleware([CheckToken::using(AgentApiScopes::TASKS_WRITE), EnsureAgentWritesEnabled::class])->name('tasks.update');
        Route::post('/workspaces/{workspace}/time-entries', [AgentTimeEntryMutationController::class, 'store'])
            ->middleware([CheckToken::using(AgentApiScopes::TIME_WRITE), EnsureAgentWritesEnabled::class])->name('time-entries.store');
        Route::patch('/workspaces/{workspace}/time-entries/{entry}', [AgentTimeEntryMutationController::class, 'update'])
            ->whereUuid('entry')->middleware([CheckToken::using(AgentApiScopes::TIME_WRITE), EnsureAgentWritesEnabled::class])->name('time-entries.update');
        Route::delete('/workspaces/{workspace}/time-entries/{entry}', [AgentTimeEntryMutationController::class, 'destroy'])
            ->whereUuid('entry')->middleware([CheckToken::using(AgentApiScopes::TIME_WRITE), EnsureAgentWritesEnabled::class])->name('time-entries.destroy');
        Route::post('/workspaces/{workspace}/time-entries/approve', [AgentTimeEntryMutationController::class, 'approve'])
            ->middleware([CheckToken::using(AgentApiScopes::TIME_APPROVE), EnsureAgentWritesEnabled::class])->name('time-entries.approve');
        Route::post('/workspaces/{workspace}/invoices', [AgentInvoiceMutationController::class, 'createDraft'])
            ->middleware([CheckToken::using(AgentApiScopes::BILLING_WRITE), EnsureAgentWritesEnabled::class])->name('invoices.store');
        Route::post('/workspaces/{workspace}/invoices/{invoice}/issue', [AgentInvoiceMutationController::class, 'issue'])
            ->whereUuid('invoice')->middleware([CheckToken::using(AgentApiScopes::BILLING_DELIVER), EnsureAgentWritesEnabled::class])->name('invoices.issue');
        Route::post('/workspaces/{workspace}/invoices/{invoice}/send', [AgentInvoiceMutationController::class, 'send'])
            ->whereUuid('invoice')->middleware([CheckToken::using(AgentApiScopes::BILLING_DELIVER), EnsureAgentWritesEnabled::class])->name('invoices.send');
        Route::post('/workspaces/{workspace}/invoices/{invoice}/void', [AgentInvoiceMutationController::class, 'void'])
            ->whereUuid('invoice')->middleware([CheckToken::using(AgentApiScopes::BILLING_DELIVER), EnsureAgentWritesEnabled::class])->name('invoices.void');
    });

Route::options('/v1/mcp', static fn () => response()->noContent())
    ->middleware('throttle:60,1')
    ->name('agent-api.v1.mcp.options');
Route::match(['POST', 'DELETE'], '/v1/mcp', AgentMcpController::class)
    ->middleware(['auth:api', CheckToken::using(AgentApiScopes::MCP_USE), 'throttle:60,1', NoStoreAgentResponse::class])
    ->name('agent-api.v1.mcp');
