<?php

use App\Http\Controllers\AccountPreferencesController;
use App\Http\Controllers\Billing\InvoiceProformaController;
use App\Http\Controllers\Billing\PortalBillingController;
use App\Http\Controllers\Billing\PortalPaymentMethodController;
use App\Http\Controllers\ClientDirectoryController;
use App\Http\Controllers\ClientPortalController;
use App\Http\Controllers\Clients\ClientAgreementsController;
use App\Http\Controllers\ClientTaskController;
use App\Http\Controllers\ClientTaskOrganizationController;
use App\Http\Controllers\ClientTaskPlanningController;
use App\Http\Controllers\Finance\FinancePaymentLinkController;
use App\Http\Controllers\HistoricalLibraryController;
use App\Http\Controllers\McpCredentialSettingsController;
use App\Http\Controllers\McpSetupController;
use App\Http\Controllers\OAuthLoginController;
use App\Http\Controllers\OpenApiDocumentController;
use App\Http\Controllers\Payments\WorkspacePaymentConnectionController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SquareExploreController;
use App\Http\Controllers\WorkspaceDashboardController;
use App\Http\Controllers\WorkspaceEntryController;
use App\Http\Controllers\WorkspaceFinanceController;
use App\Http\Controllers\WorkspaceInvoiceListController;
use App\Http\Controllers\WorkspaceMyWorkController;
use App\Http\Controllers\WorkspaceSelectorController;
use App\Http\Controllers\WorkspaceTimeQueueController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PublishApiDocumentation;
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
AgentOAuthServer::routes([PublishApiDocumentation::class]);

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

    // The caller's own display preferences (how time is read). No id in the
    // path: it can only ever write the signed-in person's row.
    Route::patch('/account/preferences', [AccountPreferencesController::class, 'update'])
        ->name('account.preferences.update');

    // The "Show all tools" setting of one of the signed-in person's own MCP
    // credentials. The credential is looked up among that person's live
    // credentials, so it can only ever write their own; it grants nothing.
    Route::put('/account/mcp-credentials/connections/{client}', [McpCredentialSettingsController::class, 'connection'])
        ->middleware('throttle:30,1')
        ->name('mcp.credentials.connections.update');
    Route::put('/account/mcp-credentials/tokens/{token}', [McpCredentialSettingsController::class, 'token'])
        ->middleware('throttle:30,1')
        ->name('mcp.credentials.tokens.update');

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
    // The workspace tier: Dashboard (the landing page), the cross-client Invoices
    // and Time lists, and Finance. The binding resolves the workspace through
    // TenantRouteBinding, so a workspace this person is not in is a 404; each
    // controller then authorizes its own audience (members for the Dashboard,
    // owners and admins for the rest).
    Route::get('/workspaces/{workspace}/dashboard', WorkspaceDashboardController::class)
        ->middleware(ResolveWorkspaceNavigation::class)
        ->name('workspaces.dashboard');
    Route::get('/workspaces/{workspace}/my-work', WorkspaceMyWorkController::class)
        ->middleware(ResolveWorkspaceNavigation::class)
        ->name('workspaces.my-work');
    Route::get('/workspaces/{workspace}/all/invoices', WorkspaceInvoiceListController::class)
        ->middleware(ResolveWorkspaceNavigation::class)
        ->name('workspaces.invoices');
    Route::get('/workspaces/{workspace}/all/time', WorkspaceTimeQueueController::class)
        ->middleware(ResolveWorkspaceNavigation::class)
        ->name('workspaces.time');
    Route::get('/workspaces/{workspace}/finance', WorkspaceFinanceController::class)
        ->middleware(ResolveWorkspaceNavigation::class)
        ->name('workspaces.finance');
    // Operations (the classic tools page) is retired: its time form is on the
    // project page and Client Home, its file panels are on the proposal,
    // agreement and invoice pages, Issue and Record payment are on the invoice
    // page, and its activity feed is on Client Home. The old address still
    // answers, so a bookmark or an MCP link from before lands on the Dashboard.
    Route::redirect('/workspaces/{workspace}/operations', '/workspaces/{workspace}/dashboard')
        ->name('workspaces.operations');

    Route::middleware(ResolveWorkspaceNavigation::class)->group(function (): void {
        Route::get('/workspaces/{workspace}/task-labels', [ClientTaskPlanningController::class, 'page'])->name('tasks.planning.labels.page');
        Route::get('/workspaces/{workspace}/clients/{clientCompany}/projects/{project}/task-organization', [ClientTaskOrganizationController::class, 'page'])->whereUuid('project')->name('tasks.organization.show');
        Route::get('/workspaces/{workspace}/tasks/{clientTask}', [ClientTaskController::class, 'show'])->name('tasks.show');
        Route::get('/workspaces/{workspace}/history', [HistoricalLibraryController::class, 'index'])->name('history.index');
        Route::get('/workspaces/{workspace}/history/{historicalEntry}', [HistoricalLibraryController::class, 'show'])->name('history.show');
        Route::get('/workspaces/{workspace}/mcp', McpSetupController::class)->name('mcp.setup');
        Route::get('/workspaces/{workspace}/payments', [WorkspacePaymentConnectionController::class, 'show'])->name('workspaces.payments');
        Route::get('/workspaces/{workspace}/square', SquareExploreController::class)->name('square.explore');
        Route::get('/workspaces/{workspace}/clients', [ClientDirectoryController::class, 'index'])->name('clients.index');
        Route::get('/workspaces/{workspace}/clients/{clientCompany}', [ClientDirectoryController::class, 'show'])
            ->name('clients.show');
        Route::get('/workspaces/{workspace}/clients/{clientCompany}/agreements', ClientAgreementsController::class)
            ->name('clients.agreements');
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
        Route::get('/workspaces/{workspace}/clients/{clientCompany}/agreements/{clientAgreement}/proforma', [InvoiceProformaController::class, 'show'])
            ->name('clients.proforma');
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
        Route::get('/portal/{clientCompany}/billing', [PortalBillingController::class, 'show'])
            ->name('portal.billing');
        Route::post('/portal/{clientCompany}/autopay', [PortalBillingController::class, 'enable'])
            ->name('portal.autopay.enable');
        Route::delete('/portal/{clientCompany}/autopay', [PortalBillingController::class, 'disable'])
            ->name('portal.autopay.disable');
        Route::post('/portal/{clientCompany}/payment-methods/setup', [PortalPaymentMethodController::class, 'setup'])
            ->name('portal.payment-methods.setup');
        Route::post('/portal/{clientCompany}/payment-methods/confirm', [PortalPaymentMethodController::class, 'confirm'])
            ->name('portal.payment-methods.confirm');
        Route::post('/portal/{clientCompany}/payment-methods/{paymentMethod}/default', [PortalPaymentMethodController::class, 'makeDefault'])
            ->name('portal.payment-methods.default');
        Route::delete('/portal/{clientCompany}/payment-methods/{paymentMethod}', [PortalPaymentMethodController::class, 'destroy'])
            ->name('portal.payment-methods.destroy');
        Route::get('/portal/{clientCompany}/time', [ClientPortalController::class, 'time'])
            ->name('portal.time');
        Route::get('/portal/{clientCompany}/tasks', [ClientPortalController::class, 'tasks'])
            ->name('portal.tasks');
        Route::get('/portal/{clientCompany}/agreements/{clientAgreement}', [ClientPortalController::class, 'agreement'])
            ->name('portal.agreement');
        Route::get('/portal/{clientCompany}/proposals/{clientProposal}', [ClientPortalController::class, 'proposal'])
            ->name('portal.proposal');
    });

    // Payment keys are entered here by an owner or admin and nowhere else; the
    // controller checks the role again, and CSRF protection is the web group's.
    Route::post('/workspaces/{workspace}/payments/{processor}', [WorkspacePaymentConnectionController::class, 'store'])->name('workspaces.payments.store');
    Route::post('/workspaces/{workspace}/payments/{processor}/reuse', [WorkspacePaymentConnectionController::class, 'reuse'])->name('workspaces.payments.reuse');
    Route::delete('/workspaces/{workspace}/payments/{processor}', [WorkspacePaymentConnectionController::class, 'destroy'])->name('workspaces.payments.destroy');

    Route::get('/workspaces/{workspace}/historical-files/{historicalBlob}', [HistoricalLibraryController::class, 'download'])->name('history.download');

    foreach (TaskPlanningOperations::DEFINITIONS as $suffix => [$verb]) {
        if ($verb !== 'GET' || in_array($suffix, ['labels.options', 'dependencies.get', 'dependencies.options', 'contacts.options'], true)) {
            continue;
        }
        $path = TaskPlanningOperations::ROUTES['task_planning.'.$suffix][1];
        if ($suffix === 'labels.list') {
            $path .= '/data';
        }
        $handler = str_ends_with($suffix, '.options') ? 'options' : 'show';
        Route::match([$verb], $path, [ClientTaskPlanningController::class, $handler])
            ->whereUuid(['task', 'project', 'label'])->defaults('task_planning_operation', $suffix)->name('tasks.planning.'.$suffix);
    }
    Route::prefix('/workspaces/{workspace}/projects/{project}/task-organization')->group(function (): void {
        Route::get('/', [ClientTaskOrganizationController::class, 'show'])->whereUuid('project')->name('tasks.organization.snapshot');
    });
});

require __DIR__.'/engagement.php';
require __DIR__.'/expenses.php';
require __DIR__.'/expenses.php';
require __DIR__.'/billing.php';
require __DIR__.'/files.php';
require __DIR__.'/collaboration.php';
require __DIR__.'/shared-projects.php';
require __DIR__.'/approvals.php';
require __DIR__.'/submissions.php';
