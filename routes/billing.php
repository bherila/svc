<?php

use App\Http\Controllers\Billing\BillingScheduleController;
use App\Http\Controllers\Billing\InvoiceController;
use App\Http\Controllers\Billing\StripeWebhookController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::middleware('web')
    ->post('/api/webhooks/stripe', StripeWebhookController::class)
    ->withoutMiddleware([ValidateCsrfToken::class, PreventRequestForgery::class])
    ->name('svc.billing.stripe.webhook');

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/workspaces/{workspace}/invoices', [InvoiceController::class, 'index'])->name('svc.billing.invoices.index');
    Route::post('/workspaces/{workspace}/clients/{clientCompany}/invoices', [InvoiceController::class, 'store'])->name('svc.billing.invoices.store');
    Route::get('/workspaces/{workspace}/invoices/{clientInvoice}', [InvoiceController::class, 'show'])->name('svc.billing.invoices.show');
    Route::post('/workspaces/{workspace}/invoices/{clientInvoice}/issue', [InvoiceController::class, 'issue'])->name('svc.billing.invoices.issue');
    Route::post('/workspaces/{workspace}/invoices/{clientInvoice}/payments', [InvoiceController::class, 'payment'])->name('svc.billing.invoices.payments.store');
    Route::post('/workspaces/{workspace}/invoices/{clientInvoice}/stripe-payment-intent', [InvoiceController::class, 'stripePaymentIntent'])->name('svc.billing.invoices.stripe-payment-intent');
    Route::get('/workspaces/{workspace}/invoices/{clientInvoice}/pdf', [InvoiceController::class, 'pdf'])->name('svc.billing.invoices.pdf');
    Route::post('/workspaces/{workspace}/invoices/{clientInvoice}/send', [InvoiceController::class, 'send'])->name('svc.billing.invoices.send');
    Route::post('/workspaces/{workspace}/invoices/{clientInvoice}/void', [InvoiceController::class, 'void'])->name('svc.billing.invoices.void');

    Route::post('/workspaces/{workspace}/clients/{clientCompany}/billing-schedules', [BillingScheduleController::class, 'store'])->name('svc.billing.schedules.store');
    Route::get('/workspaces/{workspace}/billing-schedules/{schedule}', [BillingScheduleController::class, 'show'])->name('svc.billing.schedules.show');
    Route::post('/workspaces/{workspace}/billing-schedules/{schedule}/generate', [BillingScheduleController::class, 'generate'])->name('svc.billing.schedules.generate');
});
