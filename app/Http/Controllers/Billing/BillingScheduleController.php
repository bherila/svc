<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StoreBillingScheduleRequest;
use App\Models\ClientAgreement;
use App\Models\ClientBillingSchedule;
use App\Models\ClientCompany;
use App\Models\Workspace;
use App\Services\Billing\BillingScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class BillingScheduleController extends Controller
{
    public function store(StoreBillingScheduleRequest $request, Workspace $workspace, ClientCompany $clientCompany): JsonResponse|RedirectResponse
    {
        Gate::authorize('manage', $workspace);
        abort_unless($clientCompany->workspace_id === $workspace->id, 404);
        $data = $request->validated();
        $agreement = ClientAgreement::query()
            ->where('public_id', $data['client_agreement'])
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $clientCompany->id)
            ->firstOrFail();
        unset($data['client_agreement']);
        $schedule = ClientBillingSchedule::query()->create([
            ...$data,
            'workspace_id' => $workspace->id,
            'client_company_id' => $clientCompany->id,
            'client_agreement_id' => $agreement->id,
        ]);

        return $request->expectsJson()
            ? response()->json(['data' => $schedule], 201)
            : redirect()->back()->with('status', 'Billing schedule created.');
    }

    public function generate(Workspace $workspace, ClientBillingSchedule $schedule, BillingScheduleService $service): JsonResponse|RedirectResponse
    {
        Gate::authorize('manage', $workspace);
        abort_unless($schedule->workspace_id === $workspace->id, 404);
        $invoices = $service->generateDue($schedule, CarbonImmutable::today());

        return request()->expectsJson()
            ? response()->json(['data' => $invoices])
            : redirect()->back()->with('status', 'Due invoices generated.');
    }

    public function show(Workspace $workspace, ClientBillingSchedule $schedule): View
    {
        Gate::authorize('view', $workspace);
        abort_unless($schedule->workspace_id === $workspace->id, 404);

        return view('invoices.schedule', compact('workspace', 'schedule'));
    }
}
