<?php

use App\Http\Controllers\ClientTaskOrganizationController;
use App\Http\Middleware\ResolveWorkspaceNavigation;
use Illuminate\Support\Facades\Route;

// Loaded only after the disposable harness bootstrap, never by routes/.
if (! app()->environment('testing') || ! is_string(getenv('SVC_LAYOUT_RUNTIME'))
    || config('database.default') !== 'sqlite'
    || config('database.connections.sqlite.database') !== getenv('SVC_LAYOUT_RUNTIME').'/database.sqlite') {
    throw new RuntimeException('Task organization fixtures require the isolated layout database.');
}

Route::middleware(['web', 'auth:web', ResolveWorkspaceNavigation::class])->group(function (): void {
    Route::get('/synthetic-organization/{workspace}/clients/{clientCompany}/projects/{project}', [ClientTaskOrganizationController::class, 'page'])->name('tasks.organization.show');
    Route::get('/synthetic-organization/{workspace}/projects/{project}/snapshot', [ClientTaskOrganizationController::class, 'show'])->name('tasks.organization.snapshot');
    foreach ([['post', '/lists', 'lists.store', 'lists.create'], ['patch', '/lists/{list}', 'lists.update', 'lists.update'],
        ['delete', '/lists/{list}', 'lists.archive', 'lists.archive'], ['post', '/lists/{list}/restore', 'lists.restore', 'lists.restore'],
        ['put', '/lists/order', 'lists.reorder', 'lists.reorder'], ['post', '/tasks', 'tasks.store', 'tasks.create'],
        ['patch', '/tasks/{task}/container', 'tasks.move', 'tasks.move'], ['put', '/tasks/order', 'tasks.reorder', 'tasks.reorder']] as [$verb, $suffix, $name, $operation]) {
        Route::$verb('/synthetic-organization/{workspace}/projects/{project}'.$suffix, [ClientTaskOrganizationController::class, $operation === 'lists.archive' ? 'archiveList' : 'mutate'])
            ->defaults('task_organization_operation', $operation)->name('tasks.organization.'.$name);
    }
});
Route::getRoutes()->refreshNameLookups();
