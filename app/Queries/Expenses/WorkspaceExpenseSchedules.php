<?php

namespace App\Queries\Expenses;

use App\Models\ClientCompany;
use App\Models\ClientExpense;
use App\Models\ClientExpenseSchedule;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Billing\BillingCadence;
use App\Support\Concurrency\Locks;
use App\Support\Expenses\ExpenseRecurrence;
use App\Support\Expenses\NewExpense;
use App\Support\WorkspaceClock;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** All schedule mutations serialize on the schedule; occurrences use the ordinary draft gate. */
final readonly class WorkspaceExpenseSchedules
{
    public function __construct(private Workspace $workspace) {}

    /** @return Builder<ClientExpenseSchedule> */
    public function query(): Builder
    {
        return ClientExpenseSchedule::query()->where('workspace_id', $this->workspace->id);
    }

    public function create(ClientCompany $company, ?string $projectId, NewExpense $facts, BillingCadence $cadence): ClientExpenseSchedule
    {
        abort_unless($company->workspace_id === $this->workspace->id, 404);
        $project = $this->project($company, $projectId);

        return $this->query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project?->id,
            'starts_on' => $facts->spentOn, 'cadence' => $cadence->value, 'amount' => $facts->amount,
            'currency' => $facts->currency, 'description' => $facts->description,
        ]);
    }

    public function update(string $id, ?string $projectId, NewExpense $facts, bool $active): void
    {
        $this->locked($id, function (ClientExpenseSchedule $schedule) use ($projectId, $facts, $active): void {
            $company = $this->company($schedule);
            $project = $this->project($company, $projectId);
            // Calendar anchor, cadence and cursor are deliberately immutable.
            $schedule->update(['client_project_id' => $project?->id, 'amount' => $facts->amount, 'currency' => $facts->currency,
                'description' => $facts->description, 'is_active' => $active]);
        });
    }

    public function generate(string $id, User $actor): int
    {
        return $this->locked($id, function (ClientExpenseSchedule $schedule) use ($actor): int {
            if (! $schedule->is_active) {
                return 0;
            }
            $company = $this->company($schedule);
            $project = $schedule->client_project_id === null ? null : ClientProject::query()->where('workspace_id', $this->workspace->id)
                ->where('client_company_id', $company->id)->whereKey($schedule->client_project_id)->firstOrFail();
            $calendar = new ExpenseRecurrence($schedule->starts_on, BillingCadence::from($schedule->cadence));
            $today = app(WorkspaceClock::class)->today($this->workspace)->toDateString();
            $created = 0;
            for ($processed = 0; $processed < ExpenseRecurrence::BATCH_LIMIT; $processed++) {
                $date = $calendar->occurrence($schedule->next_occurrence);
                if ($date->toDateString() > $today) {
                    break;
                }
                // Soft-deleted occurrences remain proof of materialization.
                $exists = ClientExpense::withTrashed()->where('workspace_id', $this->workspace->id)
                    ->where('client_expense_schedule_id', $schedule->id)->whereDate('occurrence_on', $date->toDateString())->exists();
                if (! $exists) {
                    $expense = (new WorkspaceExpenses($this->workspace))->record($company, $project,
                        new NewExpense($date, $schedule->amount, $schedule->currency, $schedule->description), $actor);
                    $expense->forceFill(['client_expense_schedule_id' => $schedule->id, 'occurrence_on' => $date->toDateString()])->save();
                    $created++;
                }
                $schedule->next_occurrence++;
            }
            $schedule->save();

            return $created;
        });
    }

    /** @template T
     * @param Closure(ClientExpenseSchedule): T $write
     * @return T */
    private function locked(string $id, Closure $write): mixed
    {
        return DB::transaction(fn () => $write($this->query()->where('public_id', $id)->tap(Locks::forUpdate())->firstOrFail()));
    }

    private function company(ClientExpenseSchedule $schedule): ClientCompany
    {
        return ClientCompany::query()->where('workspace_id', $this->workspace->id)->whereKey($schedule->client_company_id)->firstOrFail();
    }

    private function project(ClientCompany $company, ?string $id): ?ClientProject
    {
        return $id === null ? null : ClientProject::query()->where('workspace_id', $this->workspace->id)
            ->where('client_company_id', $company->id)->where('public_id', $id)->firstOrFail();
    }
}
