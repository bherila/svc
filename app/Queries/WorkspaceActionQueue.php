<?php

namespace App\Queries;

use App\Http\Middleware\EnsureServicePackagesEnabled;
use App\Models\ClientBillingSchedule;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProposal;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\ServicePackageEnrollment;
use App\Models\ServicePackageRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\ProjectAccess;
use App\Support\Billing\InvoiceStatus;
use App\Support\Packages\EnrollmentStatus;
use App\Support\Packages\PackageRequestStatus;
use App\Support\WorkspaceClock;

/**
 * The Dashboard's "Needs you" list: one row per thing to do, most urgent first.
 *
 * Two audiences, decided here and not in the browser:
 *
 * - An owner or admin gets the workspace queue: overdue invoices, billing
 *   schedules due, drafts ready to issue, time waiting for approval and
 *   proposals waiting for a signature. These rows carry money.
 * - Any other member gets only their own queue: their draft time and the open
 *   tasks assigned to them, inside projects they belong to. Those rows carry no
 *   amount, no invoice and no rate. (There is no review concept to list yet.)
 *
 * Every read is workspace-scoped and bounded: at most `LIMIT` rows per kind and
 * a fixed number of queries whatever the size of the workspace. A row names its
 * client from a workspace-scoped lookup and is dropped if that lookup misses,
 * so a row whose company id belongs to another tenant surfaces nothing.
 *
 * Each row's URL is finished here, after the scoping.
 */
final class WorkspaceActionQueue
{
    public const int LIMIT = 10;

    public function __construct(
        private readonly ProjectAccess $access,
        private readonly WorkspaceClock $clock,
    ) {}

    /**
     * @return list<array{id: string, kind: string, title: string, client: string, href: string, action: string, amount: int|null, currency: string|null, date: string|null, count: int|null, minutes: int|null}>
     */
    public function forManager(Workspace $workspace): array
    {
        $today = $this->clock->today($workspace);
        $rows = [];

        $overdue = ClientInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
            ->where('balance_amount', '>', 0)
            ->whereNotNull('due_date')
            ->where('due_date', '<', $today->toDateString())
            ->orderBy('due_date')->orderBy('id')
            ->limit(self::LIMIT)->get();
        $drafts = ClientInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', InvoiceStatus::Draft->value)
            ->orderBy('id')
            ->limit(self::LIMIT)->get();
        $schedules = ClientBillingSchedule::query()
            ->where('workspace_id', $workspace->id)
            ->where('is_active', true)
            ->where('next_run_on', '<=', $today->toDateString())
            ->orderBy('next_run_on')->orderBy('id')
            ->limit(self::LIMIT)->get();
        $proposals = ClientProposal::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', 'sent')
            ->orderBy('sent_at')->orderBy('id')
            ->limit(self::LIMIT)->get();
        $time = ClientTimeEntry::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', 'draft')
            ->where('is_billable', true)
            ->groupBy('client_company_id')
            ->selectRaw('client_company_id, count(*) as entries, sum(minutes) as minutes, min(worked_on) as oldest')
            ->orderBy('oldest')->orderBy('client_company_id')
            ->limit(self::LIMIT)->get();

        $names = $this->companyNames($workspace, array_merge(
            $overdue->pluck('client_company_id')->all(),
            $drafts->pluck('client_company_id')->all(),
            $schedules->pluck('client_company_id')->all(),
            $proposals->pluck('client_company_id')->all(),
            $time->pluck('client_company_id')->all(),
        ));

        foreach ($overdue as $invoice) {
            $company = $this->company($names, (int) $invoice->client_company_id);

            if ($company === null) {
                continue;
            }

            $rows[] = $this->row('overdue-'.$invoice->public_id, 'overdue_invoice', 'Invoice '.$invoice->invoice_number, $company['name'],
                route('clients.invoice', [$workspace, $company['public_id'], $invoice], absolute: false), 'Review',
                amount: $invoice->balance_amount, currency: $invoice->currency, date: $invoice->due_date?->toDateString());
        }

        foreach ($schedules as $schedule) {
            $company = $this->company($names, (int) $schedule->client_company_id);

            if ($company === null) {
                continue;
            }

            $rows[] = $this->row('schedule-'.$schedule->public_id, 'schedule_due', 'Billing schedule due', $company['name'],
                route('svc.billing.schedules.show', [$workspace, $schedule], absolute: false), 'Open',
                date: $schedule->next_run_on->toDateString());
        }

        foreach ($drafts as $invoice) {
            $company = $this->company($names, (int) $invoice->client_company_id);

            if ($company === null) {
                continue;
            }

            $rows[] = $this->row('draft-'.$invoice->public_id, 'draft_invoice', 'Draft '.$invoice->invoice_number, $company['name'],
                route('clients.invoice', [$workspace, $company['public_id'], $invoice], absolute: false), 'Review',
                amount: $invoice->total_amount, currency: $invoice->currency);
        }

        foreach ($time as $group) {
            $company = $this->company($names, (int) $group->client_company_id);

            if ($company === null) {
                continue;
            }

            $rows[] = $this->row('time-'.$company['public_id'], 'unapproved_time', 'Time to approve', $company['name'],
                route('clients.time', [$workspace, $company['public_id']], absolute: false), 'Review',
                count: self::integer($group->getAttribute('entries')), minutes: self::integer($group->getAttribute('minutes')));
        }

        foreach ($proposals as $proposal) {
            $company = $this->company($names, (int) $proposal->client_company_id);

            if ($company === null) {
                continue;
            }

            $rows[] = $this->row('proposal-'.$proposal->public_id, 'proposal_awaiting', $proposal->title, $company['name'],
                route('clients.proposal', [$workspace, $company['public_id'], $proposal], absolute: false), 'Open',
                date: $proposal->sent_at?->toDateString());
        }

        // Sign-up requests from public package pages (PUB-8): one row for all
        // of them, since they are reviewed together on one page.
        if (EnsureServicePackagesEnabled::enabled()) {
            $requests = ServicePackageRequest::query()
                ->where('workspace_id', $workspace->id)
                ->where('status', PackageRequestStatus::New->value)
                ->selectRaw('count(*) as requests, min(submitted_at) as oldest')
                ->first();
            $count = self::integer($requests?->getAttribute('requests'));
            if ($count > 0) {
                $oldest = $requests?->getAttribute('oldest');
                $rows[] = $this->row('package-requests', 'package_requests', $count === 1 ? 'Sign-up request to review' : 'Sign-up requests to review', '',
                    route('packages.requests.index', $workspace, absolute: false), 'Review',
                    date: is_string($oldest) ? substr($oldest, 0, 10) : null, count: $count);
            }

            // Accepted services waiting for an owner or admin to start them
            // (ACC-7): one row each, linking to the client's agreement, where
            // it is activated.
            $awaiting = ServicePackageEnrollment::query()
                ->where('workspace_id', $workspace->id)
                ->where('status', EnrollmentStatus::AwaitingReview->value)
                ->with([
                    'agreement' => fn ($agreement) => $agreement->where('workspace_id', $workspace->id),
                    'acceptedVersion' => fn ($version) => $version->where('workspace_id', $workspace->id),
                ])
                ->orderBy('accepted_at')->orderBy('id')
                ->limit(self::LIMIT)->get();
            $enrollmentNames = $this->companyNames($workspace, $awaiting->pluck('client_company_id')->all());
            foreach ($awaiting as $enrollment) {
                $company = $this->company($enrollmentNames, $enrollment->client_company_id);
                $agreement = $enrollment->agreement;
                if ($company === null || $agreement === null) {
                    continue;
                }

                $rows[] = $this->row('enrollment-'.$enrollment->public_id, 'enrollment_review', ($enrollment->acceptedVersion->name ?? $agreement->title).' to activate', $company['name'],
                    route('clients.agreement', [$workspace, $company['public_id'], $agreement], absolute: false), 'Review',
                    date: $enrollment->accepted_at?->toDateString());
            }
        }

        return $rows;
    }

    /**
     * What a member who is not an owner or admin sees: their own work only.
     *
     * @return list<array{id: string, kind: string, title: string, client: string, href: string, action: string, amount: int|null, currency: string|null, date: string|null, count: int|null, minutes: int|null}>
     */
    public function forMember(Workspace $workspace, User $user): array
    {
        $projectIds = $this->access->viewableProjectIds($user, $workspace) ?? [];

        if ($projectIds === []) {
            return [];
        }

        $rows = [];
        $time = ClientTimeEntry::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->where('status', 'draft')
            ->whereIn('client_project_id', $projectIds)
            ->groupBy('client_company_id')
            ->selectRaw('client_company_id, count(*) as entries, sum(minutes) as minutes, min(worked_on) as oldest')
            ->orderBy('oldest')->orderBy('client_company_id')
            ->limit(self::LIMIT)->get();
        $tasks = ClientTask::query()
            ->select('client_tasks.*', 'client_projects.client_company_id as company_id')
            ->join('client_task_assignments', function ($join) use ($workspace, $user): void {
                $join->on('client_task_assignments.client_task_id', '=', 'client_tasks.id')
                    ->where('client_task_assignments.workspace_id', '=', $workspace->id)
                    ->where('client_task_assignments.user_id', '=', $user->id);
            })
            ->join('client_projects', function ($join) use ($workspace): void {
                $join->on('client_projects.id', '=', 'client_tasks.client_project_id')
                    ->where('client_projects.workspace_id', '=', $workspace->id);
            })
            ->where('client_tasks.workspace_id', $workspace->id)
            ->whereIn('client_tasks.client_project_id', $projectIds)
            ->where('client_tasks.status', '!=', 'completed')
            ->orderByRaw('client_tasks.due_on is null')->orderBy('client_tasks.due_on')->orderBy('client_tasks.id')
            ->limit(self::LIMIT)->get();

        $names = $this->companyNames($workspace, array_merge(
            $time->pluck('client_company_id')->all(),
            $tasks->pluck('company_id')->all(),
        ));

        foreach ($time as $group) {
            $company = $this->company($names, (int) $group->client_company_id);

            if ($company === null) {
                continue;
            }

            $rows[] = $this->row('my-time-'.$company['public_id'], 'my_draft_time', 'Your draft time', $company['name'],
                route('clients.time', [$workspace, $company['public_id']], absolute: false), 'Open',
                count: self::integer($group->getAttribute('entries')), minutes: self::integer($group->getAttribute('minutes')));
        }

        foreach ($tasks as $task) {
            $company = $this->company($names, self::integer($task->getAttribute('company_id')));

            if ($company === null) {
                continue;
            }

            $rows[] = $this->row('my-task-'.$task->public_id, 'my_task', $task->title, $company['name'],
                route('tasks.show', [$workspace, $task], absolute: false), 'Open',
                date: $task->due_on?->toDateString());
        }

        return $rows;
    }

    /** An aggregate or joined column arrives untyped; a missing or non-numeric one is zero. */
    private static function integer(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param  array<mixed>  $ids
     * @return array<int, array{name: string, public_id: string}>
     */
    private function companyNames(Workspace $workspace, array $ids): array
    {
        $ids = array_values(array_unique(array_map(self::integer(...), $ids)));

        if ($ids === []) {
            return [];
        }

        $names = [];

        foreach (ClientCompany::query()->where('workspace_id', $workspace->id)->whereIn('id', $ids)->get(['id', 'public_id', 'name']) as $company) {
            $names[(int) $company->id] = ['name' => (string) $company->name, 'public_id' => (string) $company->public_id];
        }

        return $names;
    }

    /**
     * @param  array<int, array{name: string, public_id: string}>  $names
     * @return array{name: string, public_id: string}|null
     */
    private function company(array $names, int $id): ?array
    {
        return $names[$id] ?? null;
    }

    /**
     * @return array{id: string, kind: string, title: string, client: string, href: string, action: string, amount: int|null, currency: string|null, date: string|null, count: int|null, minutes: int|null}
     */
    private function row(string $id, string $kind, string $title, string $client, string $href, string $action, ?int $amount = null, ?string $currency = null, ?string $date = null, ?int $count = null, ?int $minutes = null): array
    {
        return compact('id', 'kind', 'title', 'client', 'href', 'action', 'amount', 'currency', 'date', 'count', 'minutes');
    }
}
