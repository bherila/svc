<?php

use App\Http\Controllers\Expenses\ExpenseController;
use App\Http\Middleware\ResolveWorkspaceNavigation;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    // A tab of one client, like the time sheet: the company is not a filter
    // the page chooses, it is where the operator already is, so it is bound by
    // route and the page carries no picker of its own.
    Route::get('/workspaces/{workspace}/clients/{clientCompany}/expenses', [ExpenseController::class, 'index'])
        ->middleware(ResolveWorkspaceNavigation::class)
        ->name('clients.expenses');

    // The writes are keyed by the expense rather than by the company, because
    // an expense's company is already fixed on the row - re-stating it in the
    // URL would be a second answer to a question the row has already settled,
    // and the two can disagree.
    Route::post('/workspaces/{workspace}/clients/{clientCompany}/expenses', [ExpenseController::class, 'store'])
        ->name('svc.expenses.store');
    Route::patch('/workspaces/{workspace}/expenses/{expense}', [ExpenseController::class, 'update'])
        ->name('svc.expenses.update');
    Route::post('/workspaces/{workspace}/expenses/{expense}/approve', [ExpenseController::class, 'approve'])
        ->name('svc.expenses.approve');
    Route::post('/workspaces/{workspace}/expenses/{expense}/unapprove', [ExpenseController::class, 'unapprove'])
        ->name('svc.expenses.unapprove');
    Route::delete('/workspaces/{workspace}/expenses/{expense}', [ExpenseController::class, 'destroy'])
        ->name('svc.expenses.destroy');
});
