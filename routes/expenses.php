<?php

use App\Http\Controllers\Expenses\ExpenseController;
use App\Http\Controllers\Expenses\ExpenseReceiptController;
use App\Http\Controllers\Expenses\ExpenseScheduleController;
use App\Http\Middleware\ResolveWorkspaceNavigation;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/workspaces/{workspace}/clients/{clientCompany}/expense-schedules', [ExpenseScheduleController::class, 'index'])->middleware(ResolveWorkspaceNavigation::class)->name('clients.expense-schedules');
    // A tab of one client, like the time sheet: the company is not a filter
    // the page chooses, it is where the operator already is, so it is bound by
    // route and the page carries no picker of its own.
    Route::get('/workspaces/{workspace}/clients/{clientCompany}/expenses', [ExpenseController::class, 'index'])
        ->middleware(ResolveWorkspaceNavigation::class)
        ->name('clients.expenses');

    Route::get('/workspaces/{workspace}/clients/{clientCompany}/expenses/{expense}/receipts', ExpenseReceiptController::class)
        ->whereUuid('expense')->middleware(ResolveWorkspaceNavigation::class)
        ->name('svc.expenses.receipts');

});
