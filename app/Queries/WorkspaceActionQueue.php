<?php

namespace App\Queries;

use App\Http\Middleware\EnsureServicePackagesEnabled;
use App\Models\AgreementCancellationRequest;
use App\Models\ClientBillingSchedule;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProposal;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\ServicePackageEnrollment;
use App\Models\ServicePackageMigrationTarget;
use App\Models\ServicePackageRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\ProjectAccess;
use App\Support\Billing\InvoicePaymentStatus;
use App\Support\Billing\InvoiceStatus;
use App\Support\Engagement\Cancellation\CancellationRequestStatus;
use App\Support\Packages\AutoInvoiceOutcome;
use App\Support\Packages\EnrollmentStatus;
use App\Support\Packages\Migration\MigrationTargetStatus;
use App\Support\Packages\PackageRequestStatus;
use App\Support\WorkspaceClock;
use Illuminate\Database\Query\Builder as QueryBuilder;

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
                    'currentVersion' => fn ($version) => $version->where('workspace_id', $workspace->id),
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

                $rows[] = $this->row('enrollment-'.$enrollment->public_id, 'enrollment_review', ($enrollment->carriedVersion()?->name ?? $agreement->title).' to activate', $company['name'],
                    route('clients.agreement', [$workspace, $company['public_id'], $agreement], absolute: false), 'Review',
                    date: $enrollment->accepted_at?->toDateString());
            }

            // EDGE-1: money arrived for a sign-up after the lapse voided its
            // invoice. Nothing was refunded or activated; an owner or admin
            // decides. Seen as a payment on the void invoice that succeeded,
            // or whose success Stripe reported and SVC refused to apply.
            $paidAfterLapse = ServicePackageEnrollment::query()
                ->where('workspace_id', $workspace->id)
                ->where('status', EnrollmentStatus::Lapsed->value)
                ->whereNotNull('signup_invoice_id')
                ->whereExists(fn (QueryBuilder $payment): QueryBuilder => $payment->selectRaw('1')->from('client_invoice_payments')
                    ->whereColumn('client_invoice_payments.workspace_id', 'service_package_enrollments.workspace_id')
                    ->whereColumn('client_invoice_payments.client_invoice_id', 'service_package_enrollments.signup_invoice_id')
                    ->where(fn (QueryBuilder $money): QueryBuilder => $money
                        ->where('client_invoice_payments.status', InvoicePaymentStatus::Succeeded->value)
                        ->orWhereExists(fn (QueryBuilder $event): QueryBuilder => $event->selectRaw('1')->from('client_stripe_events')
                            ->whereColumn('client_stripe_events.object_id', 'client_invoice_payments.provider_payment_identifier')
                            ->where('client_stripe_events.event_type', 'payment_intent.succeeded')
                            ->where('client_stripe_events.status', 'failed'))))
                ->with(['signupInvoice' => fn ($invoice) => $invoice->where('workspace_id', $workspace->id)])
                ->orderBy('lapsed_at')->orderBy('id')
                ->limit(self::LIMIT)->get();
            // EDGE-2: a running service whose version requires autopay, while
            // the company's autopay is off or has no default card to charge.
            // Nothing is suspended; this only says so.
            $autopayOff = ServicePackageEnrollment::query()
                ->where('workspace_id', $workspace->id)
                ->where('status', EnrollmentStatus::Active->value)
                ->whereExists(fn (QueryBuilder $version): QueryBuilder => $version->selectRaw('1')->from('service_package_versions')
                    ->whereColumn('service_package_versions.workspace_id', 'service_package_enrollments.workspace_id')
                    ->whereRaw('service_package_versions.id = '.ServicePackageEnrollment::CARRIED_VERSION_SQL)
                    ->where('service_package_versions.require_autopay', true))
                ->where(fn ($off) => $off
                    ->whereExists(fn (QueryBuilder $company): QueryBuilder => $company->selectRaw('1')->from('client_companies')
                        ->whereColumn('client_companies.workspace_id', 'service_package_enrollments.workspace_id')
                        ->whereColumn('client_companies.id', 'service_package_enrollments.client_company_id')
                        ->whereNull('client_companies.autopay_enabled_at'))
                    ->orWhereNotExists(fn (QueryBuilder $method): QueryBuilder => $method->selectRaw('1')->from('client_stripe_payment_methods')
                        ->whereColumn('client_stripe_payment_methods.workspace_id', 'service_package_enrollments.workspace_id')
                        ->whereColumn('client_stripe_payment_methods.client_company_id', 'service_package_enrollments.client_company_id')
                        ->where('client_stripe_payment_methods.is_default', true)))
                ->with([
                    'agreement' => fn ($agreement) => $agreement->where('workspace_id', $workspace->id),
                    'acceptedVersion' => fn ($version) => $version->where('workspace_id', $workspace->id),
                    'currentVersion' => fn ($version) => $version->where('workspace_id', $workspace->id),
                ])
                ->orderBy('activated_at')->orderBy('id')
                ->limit(self::LIMIT)->get();
            // INV-1: a billing period automatic invoicing could not invoice -
            // refused by the cadence engine, or Square collects the agreement
            // (INV-3). Each run tries again; the row goes once the period is
            // invoiced, by the run or by hand.
            $autoRefused = ServicePackageEnrollment::query()
                ->where('workspace_id', $workspace->id)
                ->where('status', EnrollmentStatus::Active->value)
                ->where('auto_invoice_outcome', AutoInvoiceOutcome::Refused->value)
                ->with([
                    'agreement' => fn ($agreement) => $agreement->where('workspace_id', $workspace->id),
                    'acceptedVersion' => fn ($version) => $version->where('workspace_id', $workspace->id),
                    'currentVersion' => fn ($version) => $version->where('workspace_id', $workspace->id),
                ])
                ->orderBy('auto_invoice_period_start')->orderBy('id')
                ->limit(self::LIMIT)->get();
            $names = $this->companyNames($workspace, array_merge($paidAfterLapse->pluck('client_company_id')->all(), $autopayOff->pluck('client_company_id')->all(), $autoRefused->pluck('client_company_id')->all()));

            foreach ($paidAfterLapse as $enrollment) {
                $company = $this->company($names, $enrollment->client_company_id);
                $invoice = $enrollment->signupInvoice;
                if ($company === null || $invoice === null) {
                    continue;
                }

                $rows[] = $this->row('enrollment-paid-after-lapse-'.$enrollment->public_id, 'enrollment_paid_after_lapse', "Payment received after {$company['name']}'s sign-up lapsed", $company['name'],
                    route('clients.invoice', [$workspace, $company['public_id'], $invoice], absolute: false), 'Review',
                    amount: (int) $invoice->total_amount, currency: $invoice->currency, date: $enrollment->lapsed_at?->toDateString());
            }

            foreach ($autopayOff as $enrollment) {
                $company = $this->company($names, $enrollment->client_company_id);
                $agreement = $enrollment->agreement;
                if ($company === null || $agreement === null) {
                    continue;
                }

                $rows[] = $this->row('enrollment-autopay-off-'.$enrollment->public_id, 'enrollment_autopay_off', ($enrollment->carriedVersion()?->name ?? $agreement->title).': autopay required but off', $company['name'],
                    route('clients.agreement', [$workspace, $company['public_id'], $agreement], absolute: false), 'Review',
                    date: $enrollment->activated_at?->toDateString());
            }

            // CAN-4: a client asked to end an agreement; an owner or admin
            // approves or declines it on the agreement's page. Oldest first.
            $cancellations = AgreementCancellationRequest::query()
                ->where('workspace_id', $workspace->id)
                ->where('status', CancellationRequestStatus::Pending->value)
                ->with(['agreement' => fn ($agreement) => $agreement->where('workspace_id', $workspace->id)])
                ->orderBy('requested_at')->orderBy('id')
                ->limit(self::LIMIT)->get();
            $cancellationNames = $this->companyNames($workspace, $cancellations->pluck('client_company_id')->all());
            foreach ($cancellations as $request) {
                $company = $this->company($cancellationNames, $request->client_company_id);
                $agreement = $request->agreement;
                if ($company === null || $agreement === null) {
                    continue;
                }

                $rows[] = $this->row('cancellation-request-'.$request->public_id, 'cancellation_request', $agreement->title.': cancellation requested', $company['name'],
                    route('clients.agreement', [$workspace, $company['public_id'], $agreement], absolute: false), 'Review',
                    date: $request->requested_end_on->toDateString());
            }

            // MIG-3: a subscriber due to move that waits on an owner (an
            // invoice already sells the new period, or a request to leave
            // before it is unanswered). Each run tries it again; the row goes
            // once it moves or is skipped.
            $held = ServicePackageMigrationTarget::query()
                ->where('workspace_id', $workspace->id)
                ->where('status', MigrationTargetStatus::Scheduled->value)
                ->whereNotNull('outcome_code')
                ->with(['agreement' => fn ($agreement) => $agreement->where('workspace_id', $workspace->id)])
                ->orderBy('effective_on')->orderBy('id')
                ->limit(self::LIMIT)->get();
            // MIG-4: a scheduled move whose new price is above the client's
            // autopay limit, read against the limit as it stands now. SVC never
            // raises it; the row goes once the client does, or the move is settled.
            $belowLimit = ServicePackageMigrationTarget::query()
                ->where('workspace_id', $workspace->id)
                ->where('status', MigrationTargetStatus::Scheduled->value)
                ->whereExists(fn (QueryBuilder $company): QueryBuilder => $company->selectRaw('1')->from('client_companies')
                    ->whereColumn('client_companies.workspace_id', 'service_package_migration_targets.workspace_id')
                    ->whereColumn('client_companies.id', 'service_package_migration_targets.client_company_id')
                    ->whereNotNull('client_companies.autopay_enabled_at')
                    ->whereColumn('client_companies.autopay_limit_amount', '<', 'service_package_migration_targets.new_price_amount'))
                ->with(['agreement' => fn ($agreement) => $agreement->where('workspace_id', $workspace->id)])
                ->orderBy('effective_on')->orderBy('id')
                ->limit(self::LIMIT)->get();
            $moveNames = $this->companyNames($workspace, array_merge($held->pluck('client_company_id')->all(), $belowLimit->pluck('client_company_id')->all()));
            foreach ($held as $target) {
                $company = $this->company($moveNames, $target->client_company_id);
                $agreement = $target->agreement;
                if ($company === null || $agreement === null) {
                    continue;
                }

                $rows[] = $this->row('package-move-held-'.$target->public_id, 'package_move_held', $agreement->title.': move to new terms held', $company['name'],
                    route('clients.agreement', [$workspace, $company['public_id'], $agreement], absolute: false), 'Review',
                    date: $target->effective_on->toDateString());
            }
            foreach ($belowLimit as $target) {
                $company = $this->company($moveNames, $target->client_company_id);
                $agreement = $target->agreement;
                if ($company === null || $agreement === null) {
                    continue;
                }

                $rows[] = $this->row('package-move-autopay-'.$target->public_id, 'package_move_autopay', $agreement->title.': autopay limit below the new price', $company['name'],
                    route('clients.agreement', [$workspace, $company['public_id'], $agreement], absolute: false), 'Review',
                    amount: $target->new_price_amount, currency: $target->new_price_currency, date: $target->effective_on->toDateString());
            }

            foreach ($autoRefused as $enrollment) {
                $company = $this->company($names, $enrollment->client_company_id);
                $agreement = $enrollment->agreement;
                if ($company === null || $agreement === null) {
                    continue;
                }

                $rows[] = $this->row('enrollment-auto-invoice-'.$enrollment->public_id, 'enrollment_auto_invoice_refused', ($enrollment->carriedVersion()?->name ?? $agreement->title).': invoice not issued automatically', $company['name'],
                    route('clients.agreement', [$workspace, $company['public_id'], $agreement], absolute: false), 'Review',
                    date: $enrollment->auto_invoice_period_start?->toDateString());
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
