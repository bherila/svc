<?php

use App\Http\Controllers\Api\V1\AgentReadController;
use App\Http\Controllers\Api\V1\InvoicePaymentController;
use App\Http\Controllers\Api\V1\PaymentReconciliationController;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Support\Facades\Route;
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
    ->middleware(['auth:sanctum', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/context', [AgentReadController::class, 'context'])
            ->middleware(CheckAbilities::class.':'.AgentApiScopes::IDENTITY_READ)
            ->name('context');
        Route::get('/workspaces/{workspace}/summary', [AgentReadController::class, 'summary'])
            ->middleware(CheckAbilities::class.':'.AgentApiScopes::IDENTITY_READ)
            ->name('workspaces.summary');
        Route::get('/workspaces/{workspace}/projects', [AgentReadController::class, 'projects'])
            ->middleware(CheckAbilities::class.':'.AgentApiScopes::PROJECTS_READ)
            ->name('projects.index');
        Route::get('/workspaces/{workspace}/projects/{project}', [AgentReadController::class, 'project'])
            ->whereUuid('project')
            ->middleware(CheckAbilities::class.':'.AgentApiScopes::PROJECTS_READ)
            ->name('projects.show');
        Route::get('/workspaces/{workspace}/tasks', [AgentReadController::class, 'tasks'])
            ->middleware(CheckAbilities::class.':'.AgentApiScopes::TASKS_READ)
            ->name('tasks.index');
        Route::get('/workspaces/{workspace}/tasks/{task}', [AgentReadController::class, 'task'])
            ->whereUuid('task')
            ->middleware(CheckAbilities::class.':'.AgentApiScopes::TASKS_READ)
            ->name('tasks.show');
        Route::get('/workspaces/{workspace}/time-entries', [AgentReadController::class, 'timeEntries'])
            ->middleware(CheckAbilities::class.':'.AgentApiScopes::TIME_READ)
            ->name('time-entries.index');
        Route::get('/workspaces/{workspace}/invoices', [AgentReadController::class, 'invoices'])
            ->middleware(CheckAbilities::class.':'.AgentApiScopes::BILLING_READ)
            ->name('invoices.index');
        Route::get('/workspaces/{workspace}/invoices/{invoice}', [AgentReadController::class, 'invoice'])
            ->whereUuid('invoice')
            ->middleware(CheckAbilities::class.':'.AgentApiScopes::BILLING_READ)
            ->name('invoices.show');
    });
