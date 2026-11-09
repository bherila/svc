<?php

namespace App\Services\Packages;

use App\Models\ClientAgreement;
use App\Models\ClientAgreementRecurringItem;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ServicePackageEnrollment;
use App\Models\ServicePackageMigration;
use App\Models\ServicePackageMigrationTarget;
use App\Models\ServicePackageVersion;
use App\Models\Workspace;
use App\Services\Activity\ClientActivityRecorder;
use App\Services\Billing\AgreementSelector;
use App\Services\Billing\BillingCycleResolver;
use App\Services\Billing\BillingMaintenance;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Engagement\AgreementWorkflow;
use App\Services\Engagement\CancellationRecords;
use App\Support\Billing\ChargeCadence;
use App\Support\Billing\EmptyCadenceDraft;
use App\Support\Billing\InvoiceStatus;
use App\Support\Concurrency\Locks;
use App\Support\Packages\EnrollmentStatus;
use App\Support\Packages\Migration\MigrationDecision;
use App\Support\Packages\Migration\MigrationHold;
use App\Support\Packages\Migration\MigrationRules;
use App\Support\Packages\Migration\MigrationRunOutcome;
use App\Support\Packages\Migration\MigrationStatus;
use App\Support\Packages\Migration\MigrationTargetStatus;
use App\Support\Packages\PackageTerms;
use App\Support\WorkspaceClock;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Carries out one subscriber's move on its effective date (MIG-3).
 *
 * ## The new terms are a new agreement term, from the effective date
 *
 * The billing engine reads an agreement's terms - hourly rate, retainer fee
 * and hours, rollover, cadence - for every period it reconciles, including
 * work done before today that is invoiced after it (December's time is
 * reconciled on January's invoice). Rewriting an active agreement's terms in
 * place would therefore price the last old period's work, and its retainer
 * ledger, on the new terms. The engine's own way to change terms over time
 * is a sequence of agreement segments on one project
 * ({@see AgreementSelector}): each owns the periods
 * inside it, and a successor's start caps the one before.
 *
 * So the move ends the current agreement on the day before the effective date
 * (through {@see AgreementWorkflow::terminate()}: it is still the record of
 * what the client signed, and its last period is billed on its own terms by
 * the engine's existing settlement) and starts, on the effective date, an
 * agreement on the same project carrying the new version's terms and
 * recurring items ({@see PackageAgreementTerms}), activated through
 * {@see AgreementWorkflow::activate()}. The enrollment - the service - now
 * names that agreement and the new version (`current_version_id`; the
 * version signed is never changed). What is the client's own carries over:
 * its end date, what is left of its minimum term (a move never starts a new
 * one), a pending cancellation request and a scheduled end from the date on,
 * and the portal settings. Unused retainer hours do not: the engine's ledger
 * ends with its agreement. Nor does a setup fee: the successor continues the
 * service, so the version's setup fee is not on it.
 *
 * Scheduling refuses an agreement Square collects (INV-3, X-2: SVC never
 * reshapes it, and its series marker is unique to one agreement) and one
 * billing on another cadence than the new version (a successor's first
 * period of another length would claim work periods the old agreement's
 * invoices already did). It also refuses a version with a recurring charge
 * off the billing cycle (an annual licence in a monthly service, a one-time
 * charge): the engine anchors such a charge at its item's start, so the
 * successor would charge again what the client already paid for. Each can
 * change after scheduling - a Square link, an operator's edit of the cadence
 * or the recurring items - so all three are asked again here and hold the
 * move.
 *
 * ## Never backdated past a period
 *
 * A move held on its date (a pending request to leave, an invoice to void)
 * applies once released while the first period on the new terms is still
 * current: the successor starts on the effective date and automatic
 * invoicing sells that period, the one containing today. Released after it
 * ended, it would start the successor in the past with that period sold by
 * neither agreement, so it holds instead (`effective_date_passed`) for an
 * owner to cancel and reschedule, and the agreement in force bills every
 * period meanwhile, the one that waited for the move included
 * ({@see PackageRecurringInvoices::due()}).
 *
 * ## Nothing billed twice, nothing skipped
 *
 * A period from the effective date on that an issued invoice already sells
 * holds the move (`period_invoiced`) rather than bill it again; drafts for
 * those periods were priced on terms that no longer apply and are discarded
 * through the ordinary discard. Automatic invoicing waits for a due move
 * ({@see PackageRecurringInvoices}), so it never sells the new period on the
 * old terms.
 *
 * ## Locks
 *
 * One transaction per subscriber: the billing maintenance share lock, the
 * enrollment, the move, the target, the agreement, its pending request and
 * scheduled end, then its invoices - every first acquisition in registry
 * order, every decision on what the locks hold. A hold rolls everything back
 * and is recorded on the target in a transaction of its own; a second run, or
 * one beside it, finds the target settled.
 */
final class PackageMigrationApplier
{
    public function __construct(
        private readonly AgreementWorkflow $agreements,
        private readonly PackageAgreementTerms $terms,
        private readonly CancellationRecords $cancellations,
        private readonly InvoiceLifecycleService $invoices,
        private readonly ClientInvoicingService $invoicing,
        private readonly ClientActivityRecorder $activities,
        private readonly BillingCycleResolver $cycles = new BillingCycleResolver,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    public function applyDue(Workspace $workspace, ServicePackageMigrationTarget $candidate): MigrationRunOutcome
    {
        DB::beginTransaction();
        $committed = false;
        try {
            $outcome = $this->attempt($workspace, $candidate);
            DB::commit();
            $committed = true;

            return $outcome;
        } catch (MigrationHeld $held) {
            $hold = $held->hold;
            $detail = $held->detail;
        } catch (Throwable $failure) {
            report($failure);
            $hold = MigrationHold::Failed;
            $detail = null;
        } finally {
            if (! $committed) {
                DB::rollBack();
            }
        }

        Log::info('A package move is held.', ['workspace_id' => $workspace->public_id, 'target_id' => $candidate->public_id, 'hold' => $hold->value]);
        $this->recordHold($workspace, $candidate, $hold, $detail);

        return MigrationRunOutcome::Held;
    }

    /** @throws MigrationHeld */
    private function attempt(Workspace $workspace, ServicePackageMigrationTarget $candidate): MigrationRunOutcome
    {
        // Discarding a draft locks its time, which only a transaction that
        // began with the maintenance share lock may.
        BillingMaintenance::share($workspace->id);
        $enrollment = ServicePackageEnrollment::query()->where('workspace_id', $workspace->id)->whereKey($candidate->service_package_enrollment_id)->tap(Locks::forUpdate())->firstOrFail();
        $migration = ServicePackageMigration::query()->where('workspace_id', $workspace->id)->whereKey($candidate->service_package_migration_id)->tap(Locks::forShare())->firstOrFail();
        $target = ServicePackageMigrationTarget::query()->where('workspace_id', $workspace->id)->whereKey($candidate->getKey())->tap(Locks::forUpdate())->firstOrFail();
        $today = $this->clock->today($workspace);
        if ($migration->migrationStatus() !== MigrationStatus::Scheduled || ! MigrationRules::isDue($target->targetStatus(), $target->effective_on, $today)) {
            return MigrationRunOutcome::NotDue;
        }

        $old = ClientAgreement::query()->where('workspace_id', $workspace->id)->whereKey($target->client_agreement_id)->tap(Locks::forUpdate())->firstOrFail();
        $pending = $this->cancellations->lockPending($old);
        $scheduled = $this->cancellations->lockScheduled($old);
        $effective = CarbonImmutable::parse($target->effective_on->toDateString(), 'UTC');
        $claims = ClientInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_agreement_id', $old->id)
            ->where('status', '!=', InvoiceStatus::Void->value)
            ->whereDate('cycle_start', '>=', $effective->toDateString())
            ->orderByDesc('cycle_start')->orderByDesc('id')
            ->tap(Locks::forUpdate())
            ->get();

        $version = ServicePackageVersion::query()->where('workspace_id', $workspace->id)->whereKey($migration->to_version_id)
            ->with(['components' => fn ($components) => $components->where('workspace_id', $workspace->id)])->firstOrFail();
        $toTerms = PackageTerms::fromVersion($version);
        // The successor's first period is the old agreement's period from the
        // effective date (a move keeps the cadence, and the date is one of its
        // period starts). Once it has ended, a successor started now would
        // begin in the past with that period never invoiced: automatic
        // invoicing sells only the period containing today.
        $firstPeriod = $this->cycles->cycleContaining($old, Carbon::parse($effective->toDateString()));
        $offCycleItems = ClientAgreementRecurringItem::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_agreement_id', $old->id)
            ->where('is_active', true)
            ->where('cadence', '!=', ChargeCadence::OneTime->value)
            ->where(fn ($open) => $open->whereNull('expires_on')->orWhereDate('expires_on', '>=', $effective->toDateString()))
            ->orderBy('id')
            ->pluck('cadence')
            ->map(static fn (mixed $cadence): string => (string) $cadence)
            ->all();
        $decision = MigrationRules::decide(
            enrollmentActive: $enrollment->enrollmentStatus() === EnrollmentStatus::Active,
            carriesFromVersion: $enrollment->carriedVersionId() === $migration->from_version_id && $enrollment->client_agreement_id === $old->id,
            agreementStatus: $old->status,
            endsOn: $old->ends_on,
            effectiveOn: $effective,
            pendingEndOn: $pending?->requested_end_on,
            periodInvoiced: $claims->contains(static fn (ClientInvoice $claim): bool => $claim->status !== InvoiceStatus::Draft->value),
            squareCollected: $old->square_series_key !== null,
            cadenceChanged: $old->effectiveBillingCadence()->value !== $toTerms->billingCadence->value,
            firstPeriodEnded: $firstPeriod->end->toDateString() < $today->toDateString(),
            offCycleComponent: PackageMigrationPlanner::chargesOffCycle($toTerms) || MigrationRules::hasOffCycleCharge($old->effectiveBillingCadence()->value, array_values($offCycleItems)),
        );
        if ($decision->hold !== null) {
            throw new MigrationHeld($decision->hold);
        }
        $company = ClientCompany::query()->where('workspace_id', $workspace->id)->whereKey($old->client_company_id)->firstOrFail();
        if ($decision->skip !== null) {
            $this->settle($workspace, $migration, $target, MigrationTargetStatus::Skipped, $decision);
            $this->activities->record($workspace, $company, 'agreement.move_skipped', $old, ['migration_id' => $migration->public_id, 'reason' => $decision->skip->value], occurrence: (string) Str::uuid());

            return MigrationRunOutcome::Skipped;
        }

        $successor = $this->succeed($workspace, $company, $enrollment, $old, $version, $effective);
        if ($pending !== null) {
            $pending->forceFill(['client_agreement_id' => $successor->id])->save();
        }
        if ($scheduled !== null) {
            $scheduled->forceFill(['client_agreement_id' => $successor->id])->save();
        }
        $this->agreements->terminate($old, $effective->subDay()->toDateString());
        $this->settleLastOldPeriod($workspace, $company, $old, $claims, $effective);
        // The service names its new agreement before starting it, so the
        // activation sees the enrollment that owns it.
        $enrollment->forceFill(['client_agreement_id' => $successor->id, 'current_version_id' => $migration->to_version_id])->save();
        $successor = $this->agreements->activate($successor, null, $enrollment);
        $target->successor_agreement_id = $successor->id;
        $this->settle($workspace, $migration, $target, MigrationTargetStatus::Applied, MigrationDecision::apply());
        $this->activities->record($workspace, $company, 'agreement.moved', $old, [
            'migration_id' => $migration->public_id, 'to_agreement_id' => $successor->public_id, 'effective_on' => $effective->toDateString(),
        ], occurrence: (string) Str::uuid());
        $this->activities->record($workspace, $company, 'agreement.moved_in', $successor, [
            'migration_id' => $migration->public_id, 'from_agreement_id' => $old->public_id, 'effective_on' => $effective->toDateString(),
        ], occurrence: (string) Str::uuid());

        return MigrationRunOutcome::Applied;
    }

    /**
     * The old term's own invoice for the first period from the effective date,
     * which closes it: the agreement has ended, so it sells nothing, and its
     * recurring items end with it. Generated now, before the new agreement
     * starts, through the ordinary cadence engine, so it is the one invoice
     * that would reconcile the last old period's work (December's time, on
     * January's invoice) on the terms the work was done under. A draft
     * already made for that period is rebuilt into it, and drafts for later
     * periods are discarded.
     *
     * For an agreement that does not bill by usage - the only kind a move
     * takes ({@see MigrationRules::billsByUsage()}) - it bills nothing and is
     * discarded. When it does bill something (work or expenses that reached a
     * fixed-price service), the new agreement's first invoice would claim the
     * same work period and the engine would refuse it, so the move is held
     * for an owner instead (`last_period_to_settle`).
     *
     * @param  Collection<int, ClientInvoice>  $drafts  the old agreement's drafts from the effective date on, latest first
     *
     * @throws MigrationHeld
     */
    private function settleLastOldPeriod(Workspace $workspace, ClientCompany $company, ClientAgreement $old, Collection $drafts, CarbonImmutable $effective): void
    {
        ClientAgreementRecurringItem::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_agreement_id', $old->id)
            ->where(fn ($open) => $open->whereNull('expires_on')->orWhereDate('expires_on', '>=', $effective->toDateString()))
            ->update(['expires_on' => $effective->subDay()->toDateString()]);
        $current = null;
        try {
            foreach ($drafts as $draft) {
                if ($draft->cycle_start?->toDateString() !== $effective->toDateString()) {
                    $this->invoices->discardDraft($draft, $workspace, 'A period after the agreement ended, on a move to a new package version from '.$effective->toDateString().'.');
                } else {
                    $current = $draft;
                }
            }
        } catch (DomainException $kept) {
            throw new MigrationHeld(MigrationHold::DraftKept, $kept->getMessage());
        }
        $ended = ClientAgreement::query()->where('workspace_id', $workspace->id)->whereKey($old->id)->tap(Locks::forUpdate())->firstOrFail();
        // Asked in a savepoint and always rolled back: an empty settlement is
        // never written (no invoice number is spent on it), and a non-empty
        // one holds the move, which rolls everything back anyway.
        DB::beginTransaction();
        try {
            $settlement = EmptyCadenceDraft::load($workspace, $this->invoicing->generateCadencePeriod($company, $ended, Carbon::parse($effective->toDateString())));
            $empty = EmptyCadenceDraft::billsNothing($settlement);
            $number = $settlement->invoice_number;
        } catch (RuntimeException|DomainException $refused) {
            throw new MigrationHeld(MigrationHold::SettlementRefused, $refused->getMessage());
        } finally {
            DB::rollBack();
        }
        if (! $empty) {
            throw new MigrationHeld(MigrationHold::LastPeriodToSettle, $number);
        }
        // A draft already made for the first new period sold it on the old terms.
        if ($current !== null) {
            try {
                $this->invoices->discardDraft($current, $workspace, 'Priced on terms a move to a new package version replaced from '.$effective->toDateString().'.');
            } catch (DomainException $kept) {
                throw new MigrationHeld(MigrationHold::DraftKept, $kept->getMessage());
            }
        }
    }

    /**
     * The agreement carrying the new version's terms from the effective date,
     * as a draft on the same project, with what is the client's own carried
     * over from the agreement it follows.
     */
    private function succeed(Workspace $workspace, ClientCompany $company, ServicePackageEnrollment $enrollment, ClientAgreement $old, ServicePackageVersion $version, CarbonImmutable $effective): ClientAgreement
    {
        $project = ClientProject::query()->where('workspace_id', $workspace->id)->whereKey($enrollment->client_project_id)->firstOrFail();
        // A move continues the service: the client set up when they signed, so
        // the version's setup fee is not charged again.
        $successor = $this->terms->createDraft($workspace, $company, $project, $version, $effective, continuing: true);

        $successor = $this->agreements->update($workspace, $successor, [
            'ends_on' => $old->ends_on?->toDateString(),
            'minimum_term_months' => MigrationRules::remainingMinimumTerm($old->starts_on, $old->minimum_term_months, $effective),
            'is_visible_to_client' => (bool) $old->is_visible_to_client,
            'show_deferred_backlog_to_client' => (bool) $old->show_deferred_backlog_to_client,
            'bill_overage_interim' => $old->bill_overage_interim,
            'rollover_policy' => $old->rollover_policy,
        ]);

        return $successor;
    }

    /** Settle a target, and its move once nothing in it is still scheduled. */
    private function settle(Workspace $workspace, ServicePackageMigration $migration, ServicePackageMigrationTarget $target, MigrationTargetStatus $status, MigrationDecision $decision): void
    {
        $now = $this->clock->now()->utc();
        $target->forceFill([
            'status' => $status->value,
            'outcome_code' => $decision->skip?->value,
            'outcome_detail' => $decision->skip?->message(),
            'outcome_recorded_at' => $decision->skip === null ? $target->outcome_recorded_at : $now,
            'settled_at' => $now,
        ])->save();
        $open = ServicePackageMigrationTarget::query()
            ->where('workspace_id', $workspace->id)
            ->where('service_package_migration_id', $migration->id)
            ->where('status', MigrationTargetStatus::Scheduled->value)
            ->tap(Locks::forUpdate())
            ->exists();
        if (! $open) {
            ServicePackageMigration::query()->where('workspace_id', $workspace->id)->whereKey($migration->id)->tap(Locks::forUpdate())->firstOrFail()
                ->forceFill(['status' => MigrationStatus::Completed->value, 'completed_at' => $now])->save();
        }
    }

    /**
     * Why a due move waits, on its target, in a transaction of its own; a
     * repeat of the same reason changes nothing, so the row is not churned
     * by every hourly run.
     */
    private function recordHold(Workspace $workspace, ServicePackageMigrationTarget $candidate, MigrationHold $hold, ?string $detail): void
    {
        DB::transaction(function () use ($workspace, $candidate, $hold, $detail): void {
            $target = ServicePackageMigrationTarget::query()->where('workspace_id', $workspace->id)->whereKey($candidate->getKey())->tap(Locks::forUpdate())->first();
            if ($target === null || $target->targetStatus() !== MigrationTargetStatus::Scheduled) {
                return;
            }
            $detail = $detail === null ? $hold->message() : mb_substr($hold->message().' '.$detail, 0, 1000);
            if ($target->outcome_code === $hold->value && $target->outcome_detail === $detail) {
                return;
            }
            $target->forceFill(['outcome_code' => $hold->value, 'outcome_detail' => $detail, 'outcome_recorded_at' => $this->clock->now()->utc()])->save();
        });
    }
}
