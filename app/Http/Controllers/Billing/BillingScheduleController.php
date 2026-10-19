<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StoreBillingScheduleRequest;
use App\Models\ClientBillingSchedule;
use App\Models\ClientCompany;
use App\Models\Workspace;
use App\Services\Billing\BillingScheduleService;
use App\Services\Billing\CreateBillingScheduleAction;
use App\Services\WorkspaceAuthorization;
use App\Support\WorkspaceClock;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class BillingScheduleController extends Controller
{
    public function store(StoreBillingScheduleRequest $request, Workspace $workspace, ClientCompany $clientCompany, WorkspaceAuthorization $authorization, CreateBillingScheduleAction $create): RedirectResponse
    {
        Gate::authorize('manage', $workspace);
        $authorization->assertOwnedBy($workspace, $clientCompany);
        $create->create($workspace, $clientCompany, $request->validated());

        // No JSON shape of its own (#454): a program uses billing_schedules.create.
        return redirect()->back()->with('status', 'Billing schedule created.');
    }

    public function generate(Workspace $workspace, ClientBillingSchedule $schedule, BillingScheduleService $service, WorkspaceAuthorization $authorization, WorkspaceClock $clock): RedirectResponse
    {
        Gate::authorize('manage', $workspace);
        $authorization->assertOwnedBy($workspace, $schedule);
        // Due by the workspace's calendar, the one `issue()` dates against:
        // UTC's "today" runs ahead of a workspace west of Greenwich every
        // evening, and a period starting on that UTC date would be billed and
        // then refused as issued before its own issue date.
        $service->generateDue($schedule, $clock->today($workspace));

        return redirect()->back()->with('status', 'Due invoices generated.');
    }

    public function show(Workspace $workspace, ClientBillingSchedule $schedule, WorkspaceAuthorization $authorization): View
    {
        Gate::authorize('view', $workspace);
        $authorization->assertOwnedBy($workspace, $schedule);

        return view('invoices.schedule', compact('workspace', 'schedule'));
    }
}
