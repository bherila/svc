<?php

namespace App\Services\Packages;

use App\Models\ClientAgreement;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientTimeEntry;
use App\Models\ServicePackageEnrollment;
use App\Models\ServicePackageMigrationTarget;
use App\Models\ServicePackageVersion;
use App\Models\Workspace;
use App\Services\Billing\Balances\BillingCycle;
use App\Services\Billing\BillingCycleResolver;
use App\Services\Billing\BillingMaintenance;
use App\Services\Billing\CadencePeriodGuard;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Billing\InvoiceNumberAllocator;
use App\Services\Billing\UncoveredCadencePeriods;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\EmptyCadenceDraft;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceStatus;
use App\Support\Concurrency\Locks;
use App\Support\Packages\AutoInvoiceOutcome;
use App\Support\Packages\AutoInvoiceRefusal;
use App\Support\Packages\AutoInvoiceResult;
use App\Support\Packages\EnrollmentStatus;
use App\Support\Packages\Migration\MigrationHold;
use App\Support\Packages\Migration\MigrationRules;
use App\Support\Packages\Migration\MigrationTargetStatus;
use App\Support\Packages\PackageInvoiceIssuing;
use App\Support\WorkspaceClock;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Automatic recurring invoices for package agreements (INV-1, INV-3).
 *
 * A package version set to issue automatically - only a fixed-price one may be
 * (PKG-5) - has each billing period of its agreements invoiced and issued
 * without anyone pressing a button. Nothing here is a second billing path: a
 * period goes through exactly what `invoices.generate_period` and the
 * scheduled cadence drafts go through - {@see UncoveredCadencePeriods} to find
 * it, {@see CadencePeriodGuard} to lock and check it, the cadence engine
 * ({@see ClientInvoicingService::generateCadencePeriod()}) to write it,
 * {@see EmptyCadenceDraft} to drop one that asks for nothing - and is then
 * issued through {@see InvoiceLifecycleService::issue()}, so automatic client
 * delivery and autopay follow the company's own settings.
 *
 * Draft-mode packages (INV-2) are never touched here: their periods are
 * ordinary cadence periods, drafted by `svc:billing:generate-cadence-drafts`
 * like any other agreement's and issued only by an owner or admin.
 *
 * **Idempotent per agreement and period.** Each period is invoiced in its own
 * transaction, under the billing maintenance share lock, then the enrollment's
 * row lock, then the guard's agreement lock and its locking read of every
 * invoice claiming the period. A second run - or a concurrent one, which waits
 * on the agreement lock - finds the claim and invoices nothing. Generating and
 * issuing commit together, so there is never a claimed-but-unissued period
 * left behind by a crash.
 *
 * **Refusals reach an owner.** A refused period is rolled back and recorded on
 * the enrollment (outcome `refused`, its code and the engine's own words),
 * which Needs you lists and `enrollments.*` returns. Each run tries it again;
 * once it is invoiced - by this or by hand - the record says so and the row
 * goes. A refusal never stops another agreement's period.
 */
final class PackageRecurringInvoices
{
    public function __construct(
        private readonly UncoveredCadencePeriods $uncovered,
        private readonly CadencePeriodGuard $guard,
        private readonly ClientInvoicingService $cadence,
        private readonly InvoiceLifecycleService $invoices,
        private readonly InvoiceNumberAllocator $numbers = new InvoiceNumberAllocator,
        private readonly BillingCycleResolver $cycles = new BillingCycleResolver,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /**
     * Workspaces with at least one active enrollment that issues automatically.
     *
     * @return list<int>
     */
    public function workspaceIds(): array
    {
        return array_values(array_map('intval', ServicePackageEnrollment::query()
            ->where('status', EnrollmentStatus::Active->value)
            ->whereExists(self::automaticVersion(...))
            ->distinct()
            ->orderBy('workspace_id')
            ->pluck('workspace_id')
            ->all()));
    }

    /**
     * The agreements of this workspace whose periods this issues: those of an
     * active enrollment whose signed version is set to issue automatically.
     * `svc:billing:generate-cadence-drafts` leaves these alone, as it leaves an
     * agreement with a billing schedule to that schedule: a draft there would
     * claim the period without issuing it.
     *
     * @return array<int, ServicePackageEnrollment> keyed by agreement id
     */
    public function automaticEnrollments(Workspace $workspace): array
    {
        $enrollments = [];
        foreach (ServicePackageEnrollment::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', EnrollmentStatus::Active->value)
            ->whereExists(self::automaticVersion(...))
            ->orderBy('id')
            ->get(['id', 'public_id', 'workspace_id', 'client_company_id', 'client_agreement_id', 'status', 'accepted_version_id', 'current_version_id', 'auto_invoice_outcome', 'auto_invoice_period_start']) as $enrollment) {
            $enrollments[$enrollment->client_agreement_id] = $enrollment;
        }

        return $enrollments;
    }

    /**
     * Each automatic agreement's current period that has begun with nothing
     * billed for it - the periods the audit reports missing, held to these
     * agreements. Only the period containing today, as for the scheduled
     * drafts: an older gap is an owner's to reopen.
     *
     * One older period is this reader's own: the first period of a move that
     * never applied while it was current (held for an owner throughout). It
     * waited for the move ({@see attempt()}), so nothing else would bill it;
     * the move can no longer apply to it ({@see MigrationHold::EffectiveDatePassed}),
     * so it is billed late on the agreement in force for it, ahead of the
     * current period. It stays owed whatever becomes of the move afterwards -
     * still held, cancelled with or without the rest of its move, or replaced
     * by a new one - until an invoice covers it; only a move that applied
     * started an agreement that bills it instead.
     *
     * @param  array<int, ServicePackageEnrollment>  $enrollments  keyed by agreement id
     * @return list<array{ServicePackageEnrollment, ClientAgreement, BillingCycle}>
     */
    public function due(Workspace $workspace, array $enrollments): array
    {
        if ($enrollments === []) {
            return [];
        }
        $due = $this->waitedForMove($workspace, $enrollments);
        $this->uncovered->each($workspace, $this->clock->today($workspace), function (ClientAgreement $agreement, BillingCycle $cycle) use ($enrollments, &$due): void {
            $enrollment = $enrollments[$agreement->id] ?? null;
            if ($enrollment !== null) {
                $due[] = [$enrollment, $agreement, $cycle];
            }
        });

        return $due;
    }

    /**
     * The first period of each move that has ended without the move applying
     * to it, while nothing bills it, on the agreement still in force.
     *
     * A period waited only if its move was still scheduled when the period
     * began: a target still scheduled, or settled (cancelled, skipped) on or
     * after its effective date on the workspace's calendar. One cancelled
     * before its date never held anything back, so its period was the
     * ordinary current one, and an owner's decision about it - a void, a
     * refusal left alone - is not overruled by a late bill. An applied target
     * is never one: the agreement it named has ended, and its successor bills
     * from the date. Chosen by what happened to the period, not by what the
     * target says now (Codex P1 on #674: a held move cancelled on the next
     * period's first day, before invoicing ran, dropped the period).
     *
     * @param  array<int, ServicePackageEnrollment>  $enrollments  keyed by agreement id
     * @return list<array{ServicePackageEnrollment, ClientAgreement, BillingCycle}>
     */
    private function waitedForMove(Workspace $workspace, array $enrollments): array
    {
        $today = $this->clock->today($workspace)->toDateString();
        $targets = ServicePackageMigrationTarget::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('client_agreement_id', array_keys($enrollments))
            ->where('status', '!=', MigrationTargetStatus::Applied->value)
            ->whereDate('effective_on', '<', $today)
            ->orderBy('id')
            ->get(['id', 'client_agreement_id', 'service_package_enrollment_id', 'effective_on', 'status', 'settled_at'])
            ->filter(fn (ServicePackageMigrationTarget $target): bool => $target->deferredFirstPeriod($this->clock->now($workspace)->getTimezone()));
        if ($targets->isEmpty()) {
            return [];
        }
        $agreements = ClientAgreement::query()->where('workspace_id', $workspace->id)->whereIn('id', $targets->pluck('client_agreement_id')->all())->where('status', 'active')->orderBy('id')->get()->keyBy('id');
        $waited = [];
        $day = $this->clock->today($workspace);
        foreach ($targets as $target) {
            $agreement = $agreements->get($target->client_agreement_id);
            $enrollment = $enrollments[$target->client_agreement_id] ?? null;
            if (! $agreement instanceof ClientAgreement || $enrollment === null || $enrollment->id !== $target->service_package_enrollment_id || ! $agreement->billsOnARecurringCadence()) {
                continue;
            }
            $cycle = $this->cycles->cycleContaining($agreement, Carbon::parse($target->effective_on->toDateString()));
            if (MigrationRules::owesDeferredPeriod($cycle->start, $cycle->end, $target->effective_on, $day, $agreement->starts_on, $agreement->ends_on)) {
                $waited[$agreement->id.':'.$cycle->start->toDateString()] = [$enrollment, $agreement, $cycle];
            }
        }
        if ($waited === []) {
            return [];
        }
        $claimed = ClientInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->where(fn ($kind) => $kind->whereNull('invoice_kind')->orWhere('invoice_kind', InvoiceKind::CadencePeriod->value))
            ->where(function ($periods) use ($waited): void {
                foreach ($waited as [, $agreement, $cycle]) {
                    $periods->orWhere(fn ($period) => $period->where('client_agreement_id', $agreement->id)->whereDate('cycle_start', $cycle->start->toDateString()));
                }
            })
            ->get(['client_agreement_id', 'cycle_start', 'status'])
            ->filter(static fn (ClientInvoice $claim): bool => InvoiceStatus::claimsPeriod($claim->status))
            ->map(static fn (ClientInvoice $claim): string => $claim->client_agreement_id.':'.$claim->cycle_start?->toDateString())
            ->all();

        return array_values(array_diff_key($waited, array_flip($claimed)));
    }

    /**
     * Invoice and issue one agreement's billing period, or record why not.
     *
     * Commits only an issued invoice. Everything else rolls back, and an
     * outcome an owner should know of is then recorded on the enrollment in
     * a transaction of its own.
     */
    public function invoice(Workspace $workspace, ServicePackageEnrollment $enrollment, ClientAgreement $agreement, BillingCycle $cycle): AutoInvoiceResult
    {
        $periodStart = $cycle->start->toDateString();
        DB::beginTransaction();
        $committed = false;
        try {
            $result = $this->attempt($workspace, $enrollment, $agreement, $periodStart, $cycle->end->toDateString());
            if ($result->outcome === AutoInvoiceOutcome::Issued) {
                DB::commit();
                $committed = true;

                return $result;
            }
        } catch (HttpExceptionInterface $claimed) {
            // The guard's 409: a non-void invoice already claims the period -
            // an earlier or concurrent run, or an owner, invoiced it first.
            if ($claimed->getStatusCode() === 409) {
                $result = AutoInvoiceResult::skipped(AutoInvoiceResult::ALREADY_INVOICED);
            } else {
                report($claimed);
                $result = AutoInvoiceResult::refused(AutoInvoiceRefusal::Failed);
            }
        } catch (ValidationException) {
            // The agreement stopped being an active recurring one, or the
            // period one it bills, between the scan and the lock.
            $result = AutoInvoiceResult::skipped(AutoInvoiceResult::NOT_DUE);
        } catch (AutoInvoiceRefused $refused) {
            $result = $refused->result;
        } catch (Throwable $failure) {
            report($failure);
            $result = AutoInvoiceResult::refused(AutoInvoiceRefusal::Failed);
        } finally {
            if (! $committed) {
                DB::rollBack();
            }
        }

        if ($result->outcome !== null) {
            if ($result->outcome === AutoInvoiceOutcome::Refused) {
                Log::warning('A package agreement\'s period was not invoiced automatically.', [
                    'workspace_id' => $workspace->public_id, 'enrollment_id' => $enrollment->public_id, 'period_start' => $periodStart,
                    'refusal' => $result->refusal?->value, 'detail' => $result->detail,
                ]);
            }
            $this->recordApart($workspace, $enrollment, $periodStart, $result);
        }

        return $result;
    }

    /**
     * Mark each recorded refusal whose period has since been invoiced another
     * way as covered, so Needs you stops asking. Asked of the invoices only;
     * a period still open is left as refused.
     *
     * @param  array<int, ServicePackageEnrollment>  $enrollments  keyed by agreement id
     * @return list<ServicePackageEnrollment> the enrollments marked
     */
    public function settleCovered(Workspace $workspace, array $enrollments): array
    {
        $refused = array_filter($enrollments, static fn (ServicePackageEnrollment $enrollment): bool => $enrollment->autoInvoiceOutcome() === AutoInvoiceOutcome::Refused && $enrollment->auto_invoice_period_start !== null);
        if ($refused === []) {
            return [];
        }
        $claims = ClientInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->where(function ($periods) use ($refused): void {
                foreach ($refused as $agreementId => $enrollment) {
                    $periods->orWhere(fn ($period) => $period->where('client_agreement_id', $agreementId)
                        ->whereDate('cycle_start', (string) $enrollment->auto_invoice_period_start?->toDateString()));
                }
            })
            ->where(fn ($kind) => $kind->whereNull('invoice_kind')->orWhere('invoice_kind', InvoiceKind::CadencePeriod->value))
            // Classified below as the guard classifies a claim: every status
            // but void, an unrecognised one included.
            ->get(['client_agreement_id', 'cycle_start', 'status']);
        $settled = [];
        foreach ($refused as $agreementId => $enrollment) {
            $period = (string) $enrollment->auto_invoice_period_start?->toDateString();
            $covered = $claims->contains(static fn (ClientInvoice $claim): bool => (int) $claim->client_agreement_id === $agreementId
                && $claim->cycle_start?->toDateString() === $period && InvoiceStatus::claimsPeriod($claim->status));
            if ($covered) {
                $this->recordApart($workspace, $enrollment, $period, AutoInvoiceResult::covered());
                $settled[] = $enrollment;
            }
        }

        return $settled;
    }

    /**
     * Inside the caller's transaction: lock, check, generate, issue, record.
     *
     * @throws AutoInvoiceRefused when the period cannot be invoiced
     */
    private function attempt(Workspace $workspace, ServicePackageEnrollment $enrollment, ClientAgreement $agreement, string $periodStart, string $periodEnd): AutoInvoiceResult
    {
        // Generation locks time and tasks, which only a transaction that began
        // with the maintenance share lock may; then the enrollment, which ranks
        // before the agreement the guard locks next. No ordinary read comes
        // before the guard's agreement lock, so the snapshot every later
        // ordinary read sees is no older than that lock.
        BillingMaintenance::share($workspace->id);
        $locked = ServicePackageEnrollment::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($enrollment->getKey())
            ->tap(Locks::forUpdate())
            ->first();
        if (! $locked instanceof ServicePackageEnrollment || $locked->enrollmentStatus() !== EnrollmentStatus::Active || $locked->client_agreement_id !== $agreement->id) {
            return AutoInvoiceResult::skipped(AutoInvoiceResult::NOT_DUE);
        }
        // MIG-3: a move to a new version taking effect at this period's start
        // has not applied yet. While the period is current it is the new
        // terms' to bill, once the move has started the agreement that carries
        // them; invoiced now, the old agreement would sell it on the old
        // terms. Once it has ended the move can no longer apply to it
        // (`effective_date_passed`), so neither it nor any later period waits:
        // the agreement in force bills them. Asked with a locking read after
        // the enrollment's lock, as the move itself is decided.
        $moveDue = $periodEnd >= $this->clock->today($workspace)->toDateString() && ServicePackageMigrationTarget::query()
            ->where('workspace_id', $workspace->id)
            ->where('service_package_enrollment_id', $locked->id)
            ->where('status', MigrationTargetStatus::Scheduled->value)
            ->whereDate('effective_on', $periodStart)
            ->tap(Locks::forShare())
            ->exists();
        if ($moveDue) {
            return AutoInvoiceResult::skipped(AutoInvoiceResult::WAITS_FOR_MOVE);
        }

        // A company-wide agreement on a cadence reaches this project's work
        // too, so its invoices and this one refuse each other over the same
        // dates, whichever is written first. Automation never takes those
        // dates from it: the period is refused for an owner to decide, and
        // the company-wide agreement bills as it always has.
        // Asked first, with a share lock, before this agreement's lock and the
        // invoice claims: an agreement found in force stays as it is until
        // this commits, and nothing here waits on it while holding invoices.
        if ($this->companyWideCadenceAgreementInForce($workspace, $agreement, $periodStart)) {
            throw new AutoInvoiceRefused(AutoInvoiceResult::refused(AutoInvoiceRefusal::CompanyWideAgreement));
        }

        [$company, $record, $start] = $this->guard->lock($workspace, $agreement->public_id, $periodStart, null);
        if ($record->id !== $locked->client_agreement_id) {
            throw new LogicException('The agreement locked is not the enrollment\'s.');
        }
        $version = ServicePackageVersion::query()->where('workspace_id', $workspace->id)->whereKey($locked->carriedVersionId())->first(['id', 'invoice_issuing']);
        if (! $version instanceof ServicePackageVersion || PackageInvoiceIssuing::from($version->invoice_issuing) !== PackageInvoiceIssuing::Automatic) {
            return AutoInvoiceResult::skipped(AutoInvoiceResult::NOT_DUE);
        }
        // INV-3, X-2: an agreement carrying the billed-in-Square marker is
        // Square's to collect. Nothing is generated, so nothing can be issued.
        if ($record->square_series_key !== null) {
            throw new AutoInvoiceRefused(AutoInvoiceResult::refused(AutoInvoiceRefusal::CollectedInSquare));
        }
        // Issuing below locks the invoice's lines and the agreement's time
        // after the engine has numbered the invoice and locked milestones.
        // Taken here first, in the registry's order - the lines, the
        // workspace's numbering, the time - generating and issuing in one
        // transaction acquire nothing out of order, as acceptance does
        // ({@see PackageSignupInvoices}). The lines the issue locks are the new
        // invoice's own, so one of the agreement's ranks them; the time it
        // locks - the invoice's and the agreement's disputed history - is all
        // inside the agreement's scope, which is locked whole, so the issue
        // waits on nothing new once milestones are held.
        ClientInvoiceLine::query()->where('workspace_id', $workspace->id)->where('client_agreement_id', $record->id)->tap(Locks::forUpdate())->first(['id']);
        $this->numbers->lockNumbering($workspace->id);
        ClientTimeEntry::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)->forAgreementScope($record)->tap(Locks::forUpdate())->get(['id']);

        try {
            $draft = $this->cadence->generateCadencePeriod($company, $record, $start);
        } catch (RuntimeException $refused) {
            throw new AutoInvoiceRefused(AutoInvoiceResult::refused(AutoInvoiceRefusal::PeriodUnbillable, $refused->getMessage()));
        }
        $draft = EmptyCadenceDraft::load($workspace, $draft);
        if (EmptyCadenceDraft::billsNothing($draft)) {
            return AutoInvoiceResult::nothingToBill();
        }

        try {
            $issued = $this->invoices->issue($this->dueNotBeforeToday($workspace, $draft), $workspace);
        } catch (DomainException $refused) {
            throw new AutoInvoiceRefused(AutoInvoiceResult::refused(AutoInvoiceRefusal::IssueRefused, $refused->getMessage()));
        }
        $result = AutoInvoiceResult::issued($issued);
        $this->write($workspace, $locked, $periodStart, $result);

        return $result;
    }

    /**
     * Whether another agreement of the company, scoped to no project and
     * billing on a recurring cadence, still has periods to bill over the work
     * this period reconciles - the cycle before it, as the engine reckons it.
     * Its periods reach every project's work, this package's included
     * ({@see ClientInvoice::scopeSharingWorkWith()}). In force, or ended no
     * earlier than that work began: an ended agreement's final invoice
     * reconciles its last work, and must not find those dates taken. One
     * that starts only after the period this invoice sells has no dates here
     * yet (Codex P2 on #671).
     */
    private function companyWideCadenceAgreementInForce(Workspace $workspace, ClientAgreement $agreement, string $periodStart): bool
    {
        $months = $agreement->effectiveBillingCadence()->monthsInCycle();
        $workStart = Carbon::parse($periodStart)->subMonths($months);
        $periodEnd = Carbon::parse($periodStart)->addMonths($months)->subDay();

        return ClientAgreement::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $agreement->client_company_id)
            ->whereKeyNot($agreement->id)
            ->whereNull('client_project_id')
            ->whereIn('status', ['active', 'paused', 'terminated', 'expired'])
            ->where(fn ($end) => $end->whereNull('ends_on')->orWhere('ends_on', '>=', $workStart->toDateString()))
            ->where(fn ($start) => $start->whereNull('starts_on')->orWhere('starts_on', '<=', $periodEnd->toDateString()))
            ->tap(Locks::forShare())
            ->get(['id', 'billing_cadence', 'client_project_id', 'status', 'ends_on'])
            ->contains(static fn (ClientAgreement $other): bool => $other->billsOnARecurringCadence());
    }

    /**
     * A cadence invoice is dated the first day of the period it sells and,
     * with no due date of its own, falls due that day. Issued later than that
     * - a refusal fixed mid-month - it is due the day it is issued, not
     * overdue from the moment the client receives it.
     */
    private function dueNotBeforeToday(Workspace $workspace, ClientInvoice $draft): ClientInvoice
    {
        $today = $this->clock->today($workspace)->toDateString();
        $issueDay = $draft->issue_date?->toDateString() ?? $today;
        if ($draft->due_date !== null || $issueDay >= $today) {
            return $draft;
        }

        return $this->invoices->updateDraftDetails($draft, $workspace, AgentApiVersion::for($draft), ['due_date' => $today]);
    }

    /** Record an outcome in a transaction of its own, under the enrollment's lock, while it is still active. */
    private function recordApart(Workspace $workspace, ServicePackageEnrollment $enrollment, string $periodStart, AutoInvoiceResult $result): void
    {
        DB::transaction(function () use ($workspace, $enrollment, $periodStart, $result): void {
            $locked = ServicePackageEnrollment::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($enrollment->getKey())
                ->tap(Locks::forUpdate())
                ->first();
            if (! $locked instanceof ServicePackageEnrollment || $locked->enrollmentStatus() !== EnrollmentStatus::Active) {
                return;
            }
            // Covered only settles a refusal of that same period; it never
            // overwrites what this command did since.
            if ($result->outcome === AutoInvoiceOutcome::Covered
                && ($locked->autoInvoiceOutcome() !== AutoInvoiceOutcome::Refused || $locked->auto_invoice_period_start?->toDateString() !== $periodStart)) {
                return;
            }
            $this->write($workspace, $locked, $periodStart, $result);
        });
    }

    /**
     * Write a result on a locked enrollment. The same result again changes
     * nothing, so an hourly retry of one refusal neither moves the row's
     * version nor its recorded time.
     */
    private function write(Workspace $workspace, ServicePackageEnrollment $locked, string $periodStart, AutoInvoiceResult $result): void
    {
        $outcome = $result->outcome ?? throw new LogicException('A skipped attempt records nothing.');
        $values = [
            'auto_invoice_outcome' => $outcome->value,
            'auto_invoice_period_start' => $periodStart,
            'auto_invoice_refusal' => $result->refusal?->value,
            'auto_invoice_detail' => $result->detail,
            'auto_invoice_id' => $result->invoice?->id,
        ];
        $current = [
            'auto_invoice_outcome' => $locked->auto_invoice_outcome,
            'auto_invoice_period_start' => $locked->auto_invoice_period_start?->toDateString(),
            'auto_invoice_refusal' => $locked->auto_invoice_refusal,
            'auto_invoice_detail' => $locked->auto_invoice_detail,
            'auto_invoice_id' => $locked->auto_invoice_id,
        ];
        if ($values === $current) {
            return;
        }
        $locked->forceFill($values + ['auto_invoice_recorded_at' => $this->clock->now($workspace)->utc()])->save();
    }

    /**
     * The version the enrollment's agreement carries issues automatically, in
     * the enrollment's workspace: the version a move put it on (MIG-3), or the
     * one signed.
     */
    private static function automaticVersion(QueryBuilder $version): QueryBuilder
    {
        return $version->selectRaw('1')->from('service_package_versions')
            ->whereColumn('service_package_versions.workspace_id', 'service_package_enrollments.workspace_id')
            ->whereRaw('service_package_versions.id = '.ServicePackageEnrollment::CARRIED_VERSION_SQL)
            ->where('service_package_versions.invoice_issuing', PackageInvoiceIssuing::Automatic->value);
    }
}
