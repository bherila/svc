<?php

namespace App\Http\Controllers\Expenses;

use App\Http\Controllers\Controller;
use App\Models\ClientCompany;
use App\Models\ClientExpenseSchedule;
use App\Models\ClientProject;
use App\Models\Workspace;
use App\Queries\Expenses\WorkspaceExpenseSchedules;
use App\Services\AgentApi\AgentExpenseScheduleService;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\BillingCadence;
use App\Support\Expenses\ExpenseRecurrence;
use App\Support\WorkspaceClock;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class ExpenseScheduleController extends Controller
{
    public function index(Workspace $workspace, ClientCompany $clientCompany): Response
    {
        Gate::authorize('manage', $workspace);
        $client = $clientCompany;
        abort_unless($client->workspace_id === $workspace->id, 404);
        $today = app(WorkspaceClock::class)->today($workspace)->toDateString();
        $schedules = (new WorkspaceExpenseSchedules($workspace))->query()->where('client_company_id', $client->id)->orderByDesc('id')->paginate(25);
        $projects = ClientProject::query()->where('workspace_id', $workspace->id)->where('client_company_id', $client->id)->orderBy('name')->get()->keyBy('id');

        return Inertia::render('clients/expense-schedules', [
            'company' => ['id' => $client->public_id, 'name' => $client->name, 'version' => AgentApiVersion::for($client)],
            'pagination' => ['next' => $schedules->nextPageUrl(), 'previous' => $schedules->previousPageUrl()],
            'today' => $today, 'currency' => $workspace->default_currency,
            'urls' => ['store' => route('agent-api.v1.expense-schedules.store', $workspace, false)],
            'projects' => $projects->values()->map(fn (ClientProject $project): array => ['value' => $project->public_id, 'label' => $project->name]),
            'cadences' => array_map(fn (BillingCadence $cadence): array => ['value' => $cadence->value, 'label' => match ($cadence) {
                BillingCadence::Monthly => 'Monthly', BillingCadence::Quarterly => 'Quarterly', BillingCadence::SemiAnnual => 'Every six months', BillingCadence::Annual => 'Annually',
            }], BillingCadence::cases()),
            'schedules' => $schedules->getCollection()->map(function (ClientExpenseSchedule $schedule) use ($workspace, $today, $projects, $client): array {
                $next = (new ExpenseRecurrence($schedule->starts_on, BillingCadence::from($schedule->cadence)))->occurrence($schedule->next_occurrence)->toDateString();
                $project = $schedule->client_project_id === null ? null : $projects->get($schedule->client_project_id);

                return [...app(AgentExpenseScheduleService::class)->present($schedule, $client, $project),
                    'project_id' => $project->public_id ?? '', 'starts_on' => $schedule->starts_on->toDateString(), 'cadence' => $schedule->cadence,
                    'active' => $schedule->is_active, 'status_label' => $schedule->is_active ? 'Active' : 'Paused', 'next_on' => $next,
                    'pending' => $schedule->is_active && $next <= $today,
                    'update_url' => route('agent-api.v1.expense-schedules.update', [$workspace, $schedule->public_id], false),
                    'generate_url' => $schedule->is_active && $next <= $today ? route('agent-api.v1.expense-schedules.generate', [$workspace, $schedule->public_id], false) : null];
            }),
        ]);
    }
}
