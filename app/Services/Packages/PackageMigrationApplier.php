<?php

namespace App\Services\Packages;

use App\Models\ClientAgreement;
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
use App\Services\Billing\BillingMaintenance;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Engagement\AgreementWorkflow;
use App\Services\Engagement\CancellationRecords;
use App\Support\Billing\InvoiceStatus;
use App\Support\Concurrency\Locks;
use App\Support\Packages\EnrollmentStatus;
use App\Support\Packages\Migration\MigrationDecision;
use App\Support\Packages\Migration\MigrationHold;
use App\Support\Packages\Migration\MigrationRules;
use App\Support\Packages\Migration\MigrationRunOutcome;
use App\Support\Packages\Migration\MigrationStatus;
use App\Support\Packages\Migration\MigrationTargetStatus;
use App\Support\WorkspaceClock;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
 * the portal settings and the Square marker. Unused retainer hours do not:
 * the engine's ledger ends with its agreement.
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
        private readonly ClientActivityRecorder $activities,
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

        $decision = MigrationRules::decide(
            enrollmentActive: $enrollment->enrollmentStatus() === EnrollmentStatus::Active,
            carriesFromVersion: $enrollment->carriedVersionId() === $migration->from_version_id && $enrollment->client_agreement_id === $old->id,
            agreementStatus: $old->status,
            endsOn: $old->ends_on,
            effectiveOn: $effective,
            pendingEndOn: $pending?->requested_end_on,
            periodInvoiced: $claims->contains(static fn (ClientInvoice $claim): bool => $claim->status !== InvoiceStatus::Draft->value),
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

        $successor = $this->succeed($workspace, $company, $enrollment, $old, $migration, $effective);
        if ($pending !== null) {
            $pending->forceFill(['client_agreement_id' => $successor->id])->save();
        }
        if ($scheduled !== null) {
            $scheduled->forceFill(['client_agreement_id' => $successor->id])->save();
        }
        $this->agreements->terminate($old, $effective->subDay()->toDateString());
        // Latest first: an earlier draft a later one relies on is discarded after it.
        foreach ($claims as $draft) {
            try {
                $this->invoices->discardDraft($draft, $workspace, 'Priced on terms replaced by a move to a new package version from '.$effective->toDateString().'.');
            } catch (DomainException $kept) {
                throw new MigrationHeld(MigrationHold::DraftKept, $kept->getMessage());
            }
        }
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
     * The agreement carrying the new version's terms from the effective date,
     * as a draft on the same project, with what is the client's own carried
     * over from the agreement it follows.
     */
    private function succeed(Workspace $workspace, ClientCompany $company, ServicePackageEnrollment $enrollment, ClientAgreement $old, ServicePackageMigration $migration, CarbonImmutable $effective): ClientAgreement
    {
        $project = ClientProject::query()->where('workspace_id', $workspace->id)->whereKey($enrollment->client_project_id)->firstOrFail();
        $version = ServicePackageVersion::query()->where('workspace_id', $workspace->id)->whereKey($migration->to_version_id)
            ->with(['components' => fn ($components) => $components->where('workspace_id', $workspace->id)])->firstOrFail();
        $successor = $this->terms->createDraft($workspace, $company, $project, $version, $effective);

        $successor = $this->agreements->update($workspace, $successor, [
            'ends_on' => $old->ends_on?->toDateString(),
            'minimum_term_months' => MigrationRules::remainingMinimumTerm($old->starts_on, $old->minimum_term_months, $effective),
            'is_visible_to_client' => (bool) $old->is_visible_to_client,
            'show_deferred_backlog_to_client' => (bool) $old->show_deferred_backlog_to_client,
            'bill_overage_interim' => $old->bill_overage_interim,
            'rollover_policy' => $old->rollover_policy,
        ]);
        if ($old->square_series_key !== null) {
            // INV-3: what Square collects stays Square's.
            $successor->forceFill(['square_series_key' => $old->square_series_key, 'square_location_id' => $old->square_location_id])->save();
        }

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
