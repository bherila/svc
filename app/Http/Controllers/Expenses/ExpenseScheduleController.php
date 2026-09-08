<?php

namespace App\Http\Controllers\Expenses;

use App\Http\Controllers\Controller;
use App\Models\ClientCompany;
use App\Models\ClientExpenseSchedule;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Expenses\WorkspaceExpenseSchedules;
use App\Support\Billing\BillingCadence;
use App\Support\Expenses\ExpenseRecurrence;
use App\Support\Expenses\NewExpense;
use App\Support\WorkspaceClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
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
            'company' => ['name' => $client->name],
            'pagination' => ['next' => $schedules->nextPageUrl(), 'previous' => $schedules->previousPageUrl()],
            'today' => $today, 'currency' => $workspace->default_currency,
            'urls' => ['store' => route('svc.expense-schedules.store', [$workspace, $client], false), 'expenses' => route('clients.expenses', [$workspace, $client], false)],
            'projects' => $projects->values()->map(fn (ClientProject $project): array => ['value' => $project->public_id, 'label' => $project->name]),
            'cadences' => array_map(fn (BillingCadence $cadence): array => ['value' => $cadence->value, 'label' => match ($cadence) {
                BillingCadence::Monthly => 'Monthly', BillingCadence::Quarterly => 'Quarterly', BillingCadence::SemiAnnual => 'Every six months', BillingCadence::Annual => 'Annually',
            }], BillingCadence::cases()),
            'schedules' => $schedules->getCollection()->map(function (ClientExpenseSchedule $schedule) use ($workspace, $today, $projects): array {
                $next = (new ExpenseRecurrence($schedule->starts_on, BillingCadence::from($schedule->cadence)))->occurrence($schedule->next_occurrence)->toDateString();
                $project = $schedule->client_project_id === null ? null : $projects->get($schedule->client_project_id);

                return ['id' => $schedule->public_id, 'description' => $schedule->description, 'amount' => $schedule->amount, 'currency' => $schedule->currency,
                    'project_id' => $project->public_id ?? '', 'starts_on' => $schedule->starts_on->toDateString(), 'cadence' => $schedule->cadence,
                    'active' => $schedule->is_active, 'status_label' => $schedule->is_active ? 'Active' : 'Paused', 'next_on' => $next,
                    'pending' => $schedule->is_active && $next <= $today,
                    'update_url' => route('svc.expense-schedules.update', [$workspace, $schedule->public_id], false),
                    'generate_url' => $schedule->is_active && $next <= $today ? route('svc.expense-schedules.generate', [$workspace, $schedule->public_id], false) : null];
            }),
        ]);
    }

    public function store(Request $request, Workspace $workspace, ClientCompany $clientCompany): RedirectResponse
    {
        Gate::authorize('manage', $workspace);
        $client = $clientCompany;
        abort_unless($client->workspace_id === $workspace->id, 404);
        $request->validate(['starts_on' => ['required', 'date_format:Y-m-d', 'before:9999-01-01'], 'cadence' => ['required', Rule::enum(BillingCadence::class)]]);
        [$facts, $project] = $this->facts($request, (string) $request->input('starts_on'));
        (new WorkspaceExpenseSchedules($workspace))->create($client, $project, $facts, BillingCadence::from((string) $request->input('cadence')));

        return back();
    }

    public function update(Request $request, Workspace $workspace, string $schedule): RedirectResponse
    {
        Gate::authorize('manage', $workspace);
        $request->validate(['active' => ['required', 'boolean'], 'starts_on' => ['prohibited'], 'cadence' => ['prohibited'], 'next_occurrence' => ['prohibited']]);
        [$facts, $project] = $this->facts($request, app(WorkspaceClock::class)->today($workspace)->toDateString());
        (new WorkspaceExpenseSchedules($workspace))->update($schedule, $project, $facts, $request->boolean('active'));

        return back();
    }

    public function generate(Request $request, Workspace $workspace, string $schedule): RedirectResponse
    {
        Gate::authorize('manage', $workspace);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        (new WorkspaceExpenseSchedules($workspace))->generate($schedule, $actor);

        return back();
    }

    /** @return array{NewExpense, ?string} */
    private function facts(Request $request, string $date): array
    {
        $data = $request->validate(['amount' => ['required', 'integer', 'min:1'], 'currency' => ['required', 'string', 'size:3', 'alpha:ascii'],
            'description' => ['required', 'string', 'max:2000'], 'project_id' => ['nullable', 'uuid']]);

        return [new NewExpense(CarbonImmutable::parse($date), (int) $data['amount'], (string) $data['currency'], (string) $data['description']), $data['project_id'] ?? null];
    }
}
