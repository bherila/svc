<?php

use App\Http\Controllers\Api\V1\InvoicePaymentController;
use App\Http\Controllers\Api\V1\PaymentReconciliationController;
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
