<?php

use App\Http\Controllers\ClientDirectoryController;
use App\Http\Controllers\ClientPortalController;
use App\Http\Controllers\ClientProjectAccessController;
use App\Http\Controllers\ClientProjectController;
use App\Http\Controllers\ClientTaskController;
use App\Http\Controllers\ClientTaskOrganizationController;
use App\Http\Controllers\ClientTaskPlanningController;
use App\Http\Controllers\Finance\FinancePaymentLinkController;
use App\Http\Controllers\HistoricalLibraryController;
use App\Http\Controllers\McpSetupController;
use App\Http\Controllers\OAuthLoginController;
use App\Http\Controllers\OpenApiDocumentController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SquareExploreController;
use App\Http\Controllers\WorkspaceEntryController;
use App\Http\Controllers\WorkspaceOperationsController;
use App\Http\Controllers\WorkspaceSelectorController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveWorkspaceNavigation;
use App\Services\AgentApi\Operations\TaskPlanningOperations;
use BWH\Auth\OAuth\Server\AgentOAuthServer;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

// Discovery documents and self-registration from the agent OAuth preset: the
// authorization-server document and one protected-resource document per
// configured resource, each only at its own path-inserted URL (RFC 9728) -
// /.well-known/oauth-protected-resource/api/v1 for REST and .../api/v1/mcp for
// MCP, which is where the MCP endpoint's 401 challenge points. Browser
// agents get CORS here from oauth_server.cors; MCP's own origin and host
// validation stays on the MCP endpoint, not on public discovery.
AgentOAuthServer::routes();

Route::withoutMiddleware(['web'])->group(function (): void {
    Route::get('/api/openapi.json', OpenApiDocumentController::class)->middleware('throttle:60,1')->name('openapi.document');
});

Route::get('/login', [OAuthLoginController::class, 'redirect'])->name('login');
Route::get('/oauth/redirect', [OAuthLoginController::class, 'redirect'])
    ->withoutMiddleware(HandleInertiaRequests::class)
    ->name('oauth.redirect');
Route::get('/oauth/callback', [OAuthLoginController::class, 'callback'])->name('oauth.callback');
// Outside `auth`: an expired session's sign-out still reaches the provider
// instead of being sent to sign in. Provider identity enforcement skips it by
// name (bherila-auth.provider_identity.except_routes).
Route::post('/logout', [OAuthLoginController::class, 'logout'])->name('logout');

Route::middleware('auth')->group(function (): void {
    // Where signing in lands. One row per workspace and nothing else - the
    // question this screen asks has exactly one answer per row.
    Route::get('/app', WorkspaceSelectorController::class)->name('workspaces.index');

    // What the command palette asks as you type. Throttled rather than
    // debounced-and-trusted: the debounce lives in the browser and a browser
    // is not where a rate limit belongs.
    Route::get('/search', SearchController::class)
        ->withoutMiddleware(HandleInertiaRequests::class)
        ->middleware('throttle:120,1')
        ->name('search');

    // A payment's permanent address for an external finance system: the
    // payment's public UUID and nothing else, redirected to whichever screen
    // shows it today. Anything this reader may not open is the same 404 as a
    // payment that does not exist. Throttled so it cannot be used to probe.
    Route::get('/finance-links/payments/{payment}', FinancePaymentLinkController::class)
        ->middleware('throttle:60,1')
        ->name('finance-links.payments.show');

    // Opening a workspace, as its own step. Which client to open depends on
    // this viewer at this moment, so the selector links here instead of
    // guessing a destination it has no basis for.
    Route::get('/workspaces/{workspace}', WorkspaceEntryController::class)
        ->middleware(ResolveWorkspaceNavigation::class)
        ->name('workspaces.enter');

    // Everything below renders the workspace shell, so the switcher and the
    // module tabs are resolved once for the group rather than by a name test
    // inside the Inertia middleware. Attaching it here is also what keeps the
    // switcher's queries off the screens that show no switcher - the workspace
    // selector, webhooks, PDFs and downloads.
    // Operations is on its way out - every workflow it holds is moving to the
    // client module that owns it - so it deliberately stays outside the shell
    // rather than gaining a switcher it is about to lose.
    Route::get('/workspaces/{workspace}/operations', WorkspaceOperationsController::class)
        ->name('workspaces.operations');

    Route::middleware(ResolveWorkspaceNavigation::class)->group(function (): void {
        Route::get('/workspaces/{workspace}/task-labels', [ClientTaskPlanningController::class, 'page'])->name('tasks.planning.labels.page');
        Route::get('/workspaces/{workspace}/clients/{clientCompany}/projects/{project}/task-organization', [ClientTaskOrganizationController::class, 'page'])->whereUuid('project')->name('tasks.organization.show');
        Route::get('/workspaces/{workspace}/tasks/{clientTask}', [ClientTaskController::class, 'show'])->name('tasks.show');
        Route::get('/workspaces/{workspace}/projects/{clientProject}/task-assignees', [ClientTaskController::class, 'assignees'])->name('tasks.assignees');
        Route::get('/workspaces/{workspace}/history', [HistoricalLibraryController::class, 'index'])->name('history.index');
        Route::get('/workspaces/{workspace}/history/{historicalEntry}', [HistoricalLibraryController::class, 'show'])->name('history.show');
        Route::get('/workspaces/{workspace}/mcp', McpSetupController::class)->name('mcp.setup');
        Route::get('/workspaces/{workspace}/square', SquareExploreController::class)->name('square.explore');
        Route::get('/workspaces/{workspace}/clients', [ClientDirectoryController::class, 'index'])->name('clients.index');
        Route::get('/workspaces/{workspace}/clients/{clientCompany}', [ClientDirectoryController::class, 'show'])
            ->name('clients.show');
        Route::get('/workspaces/{workspace}/clients/{clientCompany}/invoices', [ClientDirectoryController::class, 'invoices'])
            ->name('clients.invoices');
        Route::get('/workspaces/{workspace}/clients/{clientCompany}/tasks', [ClientDirectoryController::class, 'tasks'])
            ->name('clients.tasks');
        // Client settings, not a tab. Editing the client record and its
        // projects is administration of the client rather than one of its
        // modules, so it is reached from the switcher and from Client Home.
        Route::get('/workspaces/{workspace}/clients/{clientCompany}/settings', [ClientDirectoryController::class, 'manage'])
            ->name('clients.settings');
        // The tab this used to be. Kept so links and bookmarks survive the
        // rename; it is not a tab any more, so nothing generates it.
        Route::redirect(
            '/workspaces/{workspace}/clients/{clientCompany}/manage',
            '/workspaces/{workspace}/clients/{clientCompany}/settings',
        )->name('clients.manage');
        Route::get('/workspaces/{workspace}/clients/{clientCompany}/agreements/{clientAgreement}', [ClientDirectoryController::class, 'agreement'])
            ->name('clients.agreement');
        Route::get('/workspaces/{workspace}/clients/{clientCompany}/proposals/{clientProposal}', [ClientDirectoryController::class, 'proposal'])
            ->name('clients.proposal');
        Route::get('/workspaces/{workspace}/clients/{clientCompany}/projects/{clientProject}', [ClientDirectoryController::class, 'project'])
            ->name('clients.project');
        Route::get('/workspaces/{workspace}/clients/{clientCompany}/invoices/{clientInvoice}', [ClientDirectoryController::class, 'invoice'])
            ->name('clients.invoice');

        Route::get('/portal/{clientCompany}/mcp', [McpSetupController::class, 'portal'])->name('portal.mcp.setup');
        Route::get('/portal/{clientCompany}', [ClientPortalController::class, 'show'])->name('portal.show');
        Route::get('/portal/{clientCompany}/invoices', [ClientPortalController::class, 'invoices'])
            ->name('portal.invoices');
        Route::get('/portal/{clientCompany}/invoices/{clientInvoice}', [ClientPortalController::class, 'invoice'])
            ->name('portal.invoice');
        Route::get('/portal/{clientCompany}/time', [ClientPortalController::class, 'time'])
            ->name('portal.time');
        Route::get('/portal/{clientCompany}/tasks', [ClientPortalController::class, 'tasks'])
            ->name('portal.tasks');
        Route::get('/portal/{clientCompany}/agreements/{clientAgreement}', [ClientPortalController::class, 'agreement'])
            ->name('portal.agreement');
        Route::get('/portal/{clientCompany}/proposals/{clientProposal}', [ClientPortalController::class, 'proposal'])
            ->name('portal.proposal');
    });

    Route::get('/workspaces/{workspace}/historical-files/{historicalBlob}', [HistoricalLibraryController::class, 'download'])->name('history.download');

    Route::patch('/workspaces/{workspace}/clients/{clientCompany}/projects/{clientProject}', [ClientProjectController::class, 'update'])
        ->name('projects.update');
    Route::put('/workspaces/{workspace}/clients/{clientCompany}/projects/{clientProject}/access', [ClientProjectAccessController::class, 'update'])
        ->name('projects.access.update');
    Route::post('/workspaces/{workspace}/clients/{clientCompany}/projects', [ClientProjectController::class, 'store'])
        ->name('projects.store');
    foreach (TaskPlanningOperations::DEFINITIONS as $suffix => [$verb]) {
        $path = TaskPlanningOperations::ROUTES['task_planning.'.$suffix][1];
        if ($suffix === 'labels.list') {
            $path .= '/data';
        }
        $handler = $verb === 'GET' ? (str_ends_with($suffix, '.options') ? 'options' : 'show') : ($suffix === 'labels.archive' ? 'archiveLabel' : 'mutate');
        Route::match([$verb], $path, [ClientTaskPlanningController::class, $handler])
            ->whereUuid(['task', 'project', 'label'])->defaults('task_planning_operation', $suffix)->name('tasks.planning.'.$suffix);
    }
    Route::prefix('/workspaces/{workspace}/projects/{project}/task-organization')->group(function (): void {
        Route::get('/', [ClientTaskOrganizationController::class, 'show'])->whereUuid('project')->name('tasks.organization.snapshot');
        foreach ([['post', '/lists', 'lists.store', 'lists.create'], ['patch', '/lists/{list}', 'lists.update', 'lists.update'],
            ['delete', '/lists/{list}', 'lists.archive', 'lists.archive'], ['post', '/lists/{list}/restore', 'lists.restore', 'lists.restore'],
            ['put', '/lists/order', 'lists.reorder', 'lists.reorder'], ['post', '/tasks', 'tasks.store', 'tasks.create'],
            ['patch', '/tasks/{task}/container', 'tasks.move', 'tasks.move'], ['put', '/tasks/order', 'tasks.reorder', 'tasks.reorder']] as [$verb, $suffix, $name, $operation]) {
            Route::$verb($suffix, [ClientTaskOrganizationController::class, $operation === 'lists.archive' ? 'archiveList' : 'mutate'])
                ->whereUuid(['project', 'list', 'task'])
                ->defaults('task_organization_operation', $operation)->name('tasks.organization.'.$name);
        }
    });
    Route::post('/workspaces/{workspace}/projects/{clientProject}/tasks', [ClientTaskController::class, 'store'])
        ->name('tasks.store');
    Route::patch('/workspaces/{workspace}/tasks/{clientTask}', [ClientTaskController::class, 'update'])
        ->name('tasks.update');
});

require __DIR__.'/engagement.php';
require __DIR__.'/expenses.php';
require __DIR__.'/expenses.php';
require __DIR__.'/billing.php';
require __DIR__.'/files.php';
require __DIR__.'/collaboration.php';
