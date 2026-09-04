<?php

use App\Http\Controllers\Billing\BillingScheduleController;
use App\Http\Controllers\Billing\BrevoWebhookController;
use App\Http\Controllers\Billing\InvoiceController;
use App\Http\Controllers\Billing\StripeWebhookController;
use App\Http\Middleware\ResolveWorkspaceNavigation;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::middleware('web')
    ->post('/api/webhooks/stripe', StripeWebhookController::class)
    ->withoutMiddleware([ValidateCsrfToken::class, PreventRequestForgery::class])
    ->name('svc.billing.stripe.webhook');

// What became of an invoice email. Brevo does not sign its webhooks, so the
// controller requires a configured shared token and refuses everything when
// none is set - see it for why that failure direction is the safe one.
Route::middleware(['web', 'throttle:brevo-webhooks'])
    ->post('/api/webhooks/brevo', BrevoWebhookController::class)
    ->withoutMiddleware([ValidateCsrfToken::class, PreventRequestForgery::class])
    ->name('svc.billing.brevo.webhook');

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/workspaces/{workspace}/invoices', [InvoiceController::class, 'index'])
        ->middleware(ResolveWorkspaceNavigation::class)
        ->name('svc.billing.invoices.index');
    Route::post('/workspaces/{workspace}/clients/{clientCompany}/invoices', [InvoiceController::class, 'store'])->name('svc.billing.invoices.store');
    Route::get('/workspaces/{workspace}/invoices/{clientInvoice}', [InvoiceController::class, 'show'])
        ->middleware(ResolveWorkspaceNavigation::class)
        ->name('svc.billing.invoices.show');
    Route::post('/workspaces/{workspace}/invoices/{clientInvoice}/issue', [InvoiceController::class, 'issue'])->name('svc.billing.invoices.issue');
    Route::post('/workspaces/{workspace}/invoices/{clientInvoice}/payments', [InvoiceController::class, 'payment'])->name('svc.billing.invoices.payments.store');
    Route::post('/workspaces/{workspace}/invoices/{clientInvoice}/stripe-payment-intent', [InvoiceController::class, 'stripePaymentIntent'])->name('svc.billing.invoices.stripe-payment-intent');
    Route::get('/workspaces/{workspace}/invoices/{clientInvoice}/pdf', [InvoiceController::class, 'pdf'])->name('svc.billing.invoices.pdf');
    Route::post('/workspaces/{workspace}/invoices/{clientInvoice}/send', [InvoiceController::class, 'send'])->name('svc.billing.invoices.send');
    Route::post('/workspaces/{workspace}/invoices/{clientInvoice}/void', [InvoiceController::class, 'void'])->name('svc.billing.invoices.void');

    Route::post('/workspaces/{workspace}/clients/{clientCompany}/billing-schedules', [BillingScheduleController::class, 'store'])->name('svc.billing.schedules.store');
    Route::get('/workspaces/{workspace}/billing-schedules/{schedule}', [BillingScheduleController::class, 'show'])
        ->middleware(ResolveWorkspaceNavigation::class)
        ->name('svc.billing.schedules.show');
    Route::post('/workspaces/{workspace}/billing-schedules/{schedule}/generate', [BillingScheduleController::class, 'generate'])->name('svc.billing.schedules.generate');
});
