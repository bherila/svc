<?php

use App\Http\Controllers\Expenses\ExpenseController;
use App\Http\Controllers\Expenses\ExpenseReceiptController;
use App\Http\Controllers\Expenses\ExpenseScheduleController;
use App\Http\Middleware\ResolveWorkspaceNavigation;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/workspaces/{workspace}/clients/{clientCompany}/expense-schedules', [ExpenseScheduleController::class, 'index'])->middleware(ResolveWorkspaceNavigation::class)->name('clients.expense-schedules');
    Route::post('/workspaces/{workspace}/clients/{clientCompany}/expense-schedules', [ExpenseScheduleController::class, 'store'])->name('svc.expense-schedules.store');
    Route::patch('/workspaces/{workspace}/expense-schedules/{schedule}', [ExpenseScheduleController::class, 'update'])->name('svc.expense-schedules.update');
    Route::post('/workspaces/{workspace}/expense-schedules/{schedule}/generate', [ExpenseScheduleController::class, 'generate'])->name('svc.expense-schedules.generate');
    // A tab of one client, like the time sheet: the company is not a filter
    // the page chooses, it is where the operator already is, so it is bound by
    // route and the page carries no picker of its own.
    Route::get('/workspaces/{workspace}/clients/{clientCompany}/expenses', [ExpenseController::class, 'index'])
        ->middleware(ResolveWorkspaceNavigation::class)
        ->name('clients.expenses');

    Route::get('/workspaces/{workspace}/clients/{clientCompany}/expenses/{expense}/receipts', ExpenseReceiptController::class)
        ->whereUuid('expense')->middleware(ResolveWorkspaceNavigation::class)
        ->name('svc.expenses.receipts');

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
