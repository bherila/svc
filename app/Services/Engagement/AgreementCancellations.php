<?php

namespace App\Services\Engagement;

use App\Exceptions\AgreementCancellationRefused;
use App\Models\AgreementCancellationRequest;
use App\Models\AgreementScheduledTermination;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ServicePackageEnrollment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Activity\ClientActivityRecorder;
use App\Services\Authorization\AgentAccess;
use App\Services\Authorization\CompanyContacts;
use App\Services\Packages\PackageNotifier;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Concurrency\Locks;
use App\Support\Engagement\Cancellation\CancellationRefusal;
use App\Support\Engagement\Cancellation\CancellationRequestStatus;
use App\Support\Engagement\Cancellation\CancellationRules;
use App\Support\Engagement\Cancellation\CancellationTerms;
use App\Support\Engagement\Cancellation\ScheduledTerminationSource;
use App\Support\Engagement\Cancellation\ScheduledTerminationStatus;
use App\Support\Engagement\Cancellation\TerminationRunOutcome;
use App\Support\Packages\CancellationMode;
use App\Support\WorkspaceClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Ending an agreement on a date it may end (docs/service-packages.md, CAN-2 to
 * CAN-6, EDGE-9, EDGE-11).
 *
 * A cancellation never terminates anything. Accepting one - a company
 * contact's own in `self_serve` mode, a request an owner or admin approves,
 * or an end an owner or admin schedules - keeps the agreement `active`, sets
 * its `ends_on` to the chosen period close and records a scheduled end
 * holding the `ends_on` it replaced (EDGE-9). Until that date the end can be
 * undone, which restores the previous end. On the date,
 * `svc:agreements:terminate-scheduled` calls {@see terminateDue()}, which
 * terminates through {@see AgreementWorkflow::terminate()}, so billing after
 * it follows the existing termination rules (CAN-5).
 *
 * The only end dates accepted are the ones {@see CancellationTerms::offer()}
 * offers on the workspace's today (CAN-2); waiving notice is an owner's or
 * admin's alone, and the minimum term still holds. A balance owed never
 * blocks a cancellation and is never touched by one (EDGE-11).
 *
 * Locks, in registry order: the agreement, then its pending request, then
 * its scheduled end, each re-read under the lock and every refusal decided
 * against what the lock holds. Who may act is checked again under the lock.
 * Emails go after commit through {@see PackageNotifier} (X-5).
 */
final class AgreementCancellations
{
    public function __construct(
        private readonly AgentAccess $access,
        private readonly CompanyContacts $contacts,
        private readonly AgreementWorkflow $agreements,
        private readonly CancellationRecords $records,
        private readonly ClientActivityRecorder $activities,
        private readonly PackageNotifier $notifier,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /**
     * A company contact cancels from the portal (CAN-3): in `self_serve`
     * mode the end is scheduled at once on the chosen date; in `request`
     * mode a pending request waits for an owner or admin. `none` offers
     * nothing.
     */
    public function cancelByContact(User $contact, ClientCompany $company, ClientAgreement $agreement, string $endOn, ?string $reason): AgreementCancellationRequest|AgreementScheduledTermination
    {
        $this->assertCompanysAgreement($company, $agreement);
        $date = self::date($endOn);
        $reason = self::text($reason);
        $workspace = $this->workspaceOf($company);

        return DB::transaction(function () use ($contact, $company, $agreement, $date, $reason, $workspace): AgreementCancellationRequest|AgreementScheduledTermination {
            $locked = $this->lockAgreement($workspace, $agreement->id);
            $this->requireCompanyContact($company, $contact);
            $pending = $this->records->lockPending($locked);
            $scheduled = $this->records->lockScheduled($locked);
            $terms = CancellationTerms::of($locked);
            $refusal = CancellationRules::clientRefusal($terms->mode, $locked->status, $terms->recurring(), $scheduled !== null, $pending !== null);
            if ($refusal !== null) {
                throw AgreementCancellationRefused::because($refusal);
            }
            $this->assertOffered($terms, $workspace, $date, false);

            if ($terms->mode === CancellationMode::SelfServe) {
                $end = $this->scheduleEnd($workspace, $company, $locked, $date, ScheduledTerminationSource::SelfServe, $contact, null);
                $this->notifier->cancellationScheduled($workspace, $company->name, $locked->title, $date, $reason, $this->operatorUrl($workspace, $company, $locked));
                $this->notifier->cancellationAccepted($workspace, $company, $locked->title, $date, null, $this->portalUrl($workspace, $company, $locked));

                return $end;
            }

            $request = new AgreementCancellationRequest;
            $request->forceFill([
                'workspace_id' => $workspace->id,
                'client_company_id' => $company->id,
                'client_agreement_id' => $locked->id,
                'status' => CancellationRequestStatus::Pending->value,
                'requested_end_on' => $date->toDateString(),
                'reason' => $reason,
                'requested_by_user_id' => $contact->id,
                'requested_at' => $this->instant(),
            ])->save();
            $this->activities->record($workspace, $company, 'agreement.cancellation_requested', $locked,
                ['request_id' => $request->public_id, 'requested_end_on' => $date->toDateString()], $contact, (string) Str::uuid());
            $this->notifier->cancellationRequested($workspace, $company->name, $locked->title, $date, $reason, $this->operatorUrl($workspace, $company, $locked));

            return $request;
        });
    }

    /** A company contact withdraws their company's pending request (CAN-4). */
    public function withdrawByContact(User $contact, ClientCompany $company, ClientAgreement $agreement): AgreementCancellationRequest
    {
        $this->assertCompanysAgreement($company, $agreement);
        $workspace = $this->workspaceOf($company);

        return DB::transaction(function () use ($contact, $company, $agreement, $workspace): AgreementCancellationRequest {
            $locked = $this->lockAgreement($workspace, $agreement->id);
            $this->requireCompanyContact($company, $contact);
            $pending = $this->records->lockPending($locked) ?? throw AgreementCancellationRefused::because(CancellationRefusal::RequestNotPending);
            $pending->forceFill([
                'status' => CancellationRequestStatus::Withdrawn->value,
                'decided_by_user_id' => $contact->id,
                'decided_at' => $this->instant(),
            ])->save();
            $this->activities->record($workspace, $company, 'agreement.cancellation_withdrawn', $locked, ['request_id' => $pending->public_id], $contact, (string) Str::uuid());

            return $pending;
        });
    }

    /**
     * A company contact undoes the end they scheduled in `self_serve` mode
     * (EDGE-9). An end an owner or admin decided is theirs to undo.
     */
    public function undoByContact(User $contact, ClientCompany $company, ClientAgreement $agreement): AgreementScheduledTermination
    {
        $this->assertCompanysAgreement($company, $agreement);
        $workspace = $this->workspaceOf($company);

        return DB::transaction(function () use ($contact, $company, $agreement, $workspace): AgreementScheduledTermination {
            $locked = $this->lockAgreement($workspace, $agreement->id);
            $this->requireCompanyContact($company, $contact);

            return $this->undoLocked($workspace, $company, $locked, $contact, false);
        });
    }

    /**
     * An owner or admin approves a pending request (CAN-4): on the date the
     * client asked for, or another offered date, with notice waived if they
     * choose. The end is scheduled, and the client is told the date.
     */
    public function approve(User $actor, Workspace $workspace, AgreementCancellationRequest $request, ?string $endOn, bool $waiveNotice, ?string $note, ?string $expectedVersion = null): AgreementCancellationRequest
    {
        $this->requireManager($actor, $workspace);
        $note = self::text($note);
        $date = $endOn === null ? null : self::date($endOn);

        return DB::transaction(function () use ($actor, $workspace, $request, $date, $waiveNotice, $note, $expectedVersion): AgreementCancellationRequest {
            $locked = $this->lockAgreement($workspace, $request->client_agreement_id);
            $decided = $this->records->lockRequest($locked, $request->id);
            $this->assertVersion($decided, $expectedVersion);
            if ($decided->requestStatus() !== CancellationRequestStatus::Pending) {
                throw AgreementCancellationRefused::because(CancellationRefusal::RequestNotPending);
            }
            $scheduled = $this->records->lockScheduled($locked);
            $terms = CancellationTerms::of($locked);
            $refusal = CancellationRules::scheduleRefusal($locked->status, $terms->recurring(), $scheduled !== null);
            if ($refusal !== null) {
                throw AgreementCancellationRefused::because($refusal);
            }
            $end = $date ?? $decided->requested_end_on;
            $this->assertOffered($terms, $workspace, $end, $waiveNotice);
            $company = $this->companyOf($workspace, $locked);

            $decided->forceFill([
                'status' => CancellationRequestStatus::Approved->value,
                'decided_by_user_id' => $actor->id,
                'decided_at' => $this->instant(),
                'decided_end_on' => $end->toDateString(),
                'notice_waived' => $waiveNotice,
                'decision_note' => $note,
            ])->save();
            $this->scheduleEnd($workspace, $company, $locked, $end, ScheduledTerminationSource::Request, $actor, $decided);
            $this->notifier->cancellationAccepted($workspace, $company, $locked->title, $end, $note, $this->portalUrl($workspace, $company, $locked));

            return $decided;
        });
    }

    /** An owner or admin declines a pending request, with a note the client reads (CAN-4). */
    public function decline(User $actor, Workspace $workspace, AgreementCancellationRequest $request, ?string $note, ?string $expectedVersion = null): AgreementCancellationRequest
    {
        $this->requireManager($actor, $workspace);
        $note = self::text($note);

        return DB::transaction(function () use ($actor, $workspace, $request, $note, $expectedVersion): AgreementCancellationRequest {
            $locked = $this->lockAgreement($workspace, $request->client_agreement_id);
            $decided = $this->records->lockRequest($locked, $request->id);
            $this->assertVersion($decided, $expectedVersion);
            if ($decided->requestStatus() !== CancellationRequestStatus::Pending) {
                throw AgreementCancellationRefused::because(CancellationRefusal::RequestNotPending);
            }
            $company = $this->companyOf($workspace, $locked);
            $decided->forceFill([
                'status' => CancellationRequestStatus::Declined->value,
                'decided_by_user_id' => $actor->id,
                'decided_at' => $this->instant(),
                'decision_note' => $note,
            ])->save();
            $this->activities->record($workspace, $company, 'agreement.cancellation_declined', $locked, ['request_id' => $decided->public_id], $actor, (string) Str::uuid());
            $this->notifier->cancellationDeclined($workspace, $company, $locked->title, $note, $this->portalUrl($workspace, $company, $locked));

            return $decided;
        });
    }

    /**
     * An owner or admin schedules an agreement's end directly, in any mode,
     * on an offered date (notice waived if they choose). A pending request
     * is decided instead, so the client's own request is answered.
     */
    public function schedule(User $actor, Workspace $workspace, ClientAgreement $agreement, string $endOn, bool $waiveNotice, ?string $expectedVersion = null): AgreementScheduledTermination
    {
        $this->requireManager($actor, $workspace);
        $date = self::date($endOn);

        return DB::transaction(function () use ($actor, $workspace, $agreement, $date, $waiveNotice, $expectedVersion): AgreementScheduledTermination {
            $locked = $this->lockAgreement($workspace, $agreement->id);
            $this->assertVersion($locked, $expectedVersion);
            $pending = $this->records->lockPending($locked);
            $scheduled = $this->records->lockScheduled($locked);
            $terms = CancellationTerms::of($locked);
            $refusal = CancellationRules::scheduleRefusal($locked->status, $terms->recurring(), $scheduled !== null)
                ?? ($pending === null ? null : CancellationRefusal::RequestPending);
            if ($refusal !== null) {
                throw AgreementCancellationRefused::because($refusal);
            }
            $this->assertOffered($terms, $workspace, $date, $waiveNotice);
            $company = $this->companyOf($workspace, $locked);
            $end = $this->scheduleEnd($workspace, $company, $locked, $date, ScheduledTerminationSource::Operator, $actor, null);
            $this->notifier->cancellationAccepted($workspace, $company, $locked->title, $date, null, $this->portalUrl($workspace, $company, $locked));

            return $end;
        });
    }

    /** An owner or admin undoes a scheduled end, in any mode, until its date (EDGE-9). */
    public function undo(User $actor, Workspace $workspace, ClientAgreement $agreement, ?string $expectedVersion = null): AgreementScheduledTermination
    {
        $this->requireManager($actor, $workspace);

        return DB::transaction(function () use ($actor, $workspace, $agreement, $expectedVersion): AgreementScheduledTermination {
            $locked = $this->lockAgreement($workspace, $agreement->id);
            $this->assertVersion($locked, $expectedVersion);

            return $this->undoLocked($workspace, $this->companyOf($workspace, $locked), $locked, $actor, true);
        });
    }

    /**
     * Carry out one scheduled end on its date (CAN-5): terminate the
     * agreement on the scheduled `ends_on` through AgreementWorkflow, which
     * writes `status`, `ends_on` and `terminated_at`, records the activity and
     * marks the scheduled end terminated. Decided under the agreement's lock
     * against the workspace's calendar, so an undo racing it and a second run
     * each find it already settled.
     */
    public function terminateDue(Workspace $workspace, AgreementScheduledTermination $candidate): TerminationRunOutcome
    {
        return DB::transaction(function () use ($workspace, $candidate): TerminationRunOutcome {
            $locked = $this->lockAgreement($workspace, $candidate->client_agreement_id);
            // In registry order: any pending request, then the scheduled end.
            $this->records->lockPending($locked);
            $scheduled = $this->records->lockScheduled($locked);
            if ($scheduled === null || (int) $scheduled->id !== (int) $candidate->id) {
                return TerminationRunOutcome::NotDue;
            }
            if (! CancellationRules::isDue($scheduled->terminationStatus(), $scheduled->ends_on, $this->clock->today($workspace))) {
                return TerminationRunOutcome::NotDue;
            }
            $this->agreements->terminate($locked, $scheduled->ends_on->toDateString());

            return TerminationRunOutcome::Terminated;
        });
    }

    /**
     * Schedule the end on a locked agreement: the scheduled end holding the
     * end it replaces, the agreement's new `ends_on`, and the activity.
     */
    private function scheduleEnd(Workspace $workspace, ClientCompany $company, ClientAgreement $locked, CarbonImmutable $date, ScheduledTerminationSource $source, User $by, ?AgreementCancellationRequest $request): AgreementScheduledTermination
    {
        $previous = $locked->ends_on?->toDateString();
        $end = new AgreementScheduledTermination;
        $end->forceFill([
            'workspace_id' => $workspace->id,
            'client_agreement_id' => $locked->id,
            'agreement_cancellation_request_id' => $request?->id,
            'status' => ScheduledTerminationStatus::Scheduled->value,
            'source' => $source->value,
            'ends_on' => $date->toDateString(),
            'previous_ends_on' => $previous,
            'scheduled_by_user_id' => $by->id,
            'scheduled_at' => $this->instant(),
        ])->save();
        $locked->forceFill(['ends_on' => $date->toDateString()])->save();
        $this->activities->record($workspace, $company, 'agreement.end_scheduled', $locked, [
            'source' => $source->value,
            'changes' => ['ends_on' => ['old' => $previous, 'new' => $date->toDateString()]],
        ], $by, (string) Str::uuid());

        return $end;
    }

    private function undoLocked(Workspace $workspace, ClientCompany $company, ClientAgreement $locked, User $by, bool $byManager): AgreementScheduledTermination
    {
        $this->records->lockPending($locked);
        $scheduled = $this->records->lockScheduled($locked);
        $terms = CancellationTerms::of($locked);
        $refusal = CancellationRules::undoRefusal($scheduled?->terminationStatus(), $scheduled?->terminationSource(), $terms->mode, $byManager, $this->clock->today($workspace), $scheduled?->ends_on);
        if ($refusal !== null || $scheduled === null) {
            throw AgreementCancellationRefused::because($refusal ?? CancellationRefusal::NotScheduled);
        }

        $undone = $scheduled->ends_on;
        $restored = $scheduled->previous_ends_on;
        $scheduled->forceFill([
            'status' => ScheduledTerminationStatus::Undone->value,
            'undone_by_user_id' => $by->id,
            'undone_at' => $this->instant(),
        ])->save();
        $locked->forceFill(['ends_on' => $restored?->toDateString()])->save();
        $this->activities->record($workspace, $company, 'agreement.end_undone', $locked, [
            'changes' => ['ends_on' => ['old' => $undone->toDateString(), 'new' => $restored?->toDateString()]],
        ], $by, (string) Str::uuid());
        $this->notifier->cancellationUndone($workspace, $company, $locked->title, $undone, $restored,
            $this->operatorUrl($workspace, $company, $locked), $this->portalUrl($workspace, $company, $locked));

        return $scheduled;
    }

    /** CAN-2: only an offered date, on the workspace's today. */
    private function assertOffered(CancellationTerms $terms, Workspace $workspace, CarbonImmutable $date, bool $waiveNotice): void
    {
        $offered = $terms->offer($this->clock->today($workspace), $waiveNotice);
        if ($offered === null) {
            throw AgreementCancellationRefused::because(CancellationRefusal::NotRecurring);
        }
        if (! $offered->offers($date)) {
            throw AgreementCancellationRefused::because(CancellationRefusal::DateNotOffered);
        }
    }

    private function lockAgreement(Workspace $workspace, int $agreementId): ClientAgreement
    {
        return ClientAgreement::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($agreementId)
            ->tap(Locks::forUpdate())
            ->firstOrFail();
    }

    private function assertVersion(AgreementCancellationRequest|ClientAgreement $locked, ?string $expectedVersion): void
    {
        abort_unless($expectedVersion === null || AgentApiVersion::matches($locked, $expectedVersion), 409, 'This changed since it was read. Read it again before retrying.');
    }

    private function assertCompanysAgreement(ClientCompany $company, ClientAgreement $agreement): void
    {
        abort_unless((int) $agreement->workspace_id === (int) $company->workspace_id && (int) $agreement->client_company_id === (int) $company->id, 404);
    }

    private function requireCompanyContact(ClientCompany $company, User $contact): void
    {
        // Staff previewing the portal and project-limited contacts may look, not act.
        abort_unless($this->contacts->isCompanyContact($company, $contact), 403, 'Only a contact with access to the whole company can do this.');
    }

    private function requireManager(User $actor, Workspace $workspace): void
    {
        abort_unless($this->access->isWorkspaceManager($actor, $workspace), 404);
    }

    private function companyOf(Workspace $workspace, ClientAgreement $agreement): ClientCompany
    {
        return ClientCompany::query()->where('workspace_id', $workspace->id)->whereKey($agreement->client_company_id)->firstOrFail();
    }

    private function workspaceOf(ClientCompany $company): Workspace
    {
        return Workspace::query()->findOrFail($company->workspace_id);
    }

    /** The agreement's page for owners and admins. */
    private function operatorUrl(Workspace $workspace, ClientCompany $company, ClientAgreement $agreement): string
    {
        return route('clients.agreement', [$workspace, $company->public_id, $agreement], absolute: true);
    }

    /** Where the client reads it: the service's page for a package agreement, the agreement's otherwise. */
    private function portalUrl(Workspace $workspace, ClientCompany $company, ClientAgreement $agreement): string
    {
        $enrollment = ServicePackageEnrollment::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_agreement_id', $agreement->id)
            ->value('public_id');

        return is_string($enrollment)
            ? route('portal.services.show', [$company, $enrollment], absolute: true)
            : route('portal.agreement', [$company, $agreement], absolute: true);
    }

    /** The present instant in UTC, for an event's timestamp; dates stay the workspace's. */
    private function instant(): CarbonImmutable
    {
        return $this->clock->now()->utc();
    }

    private static function date(string $value): CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        if (! $date instanceof CarbonImmutable || $date->toDateString() !== $value) {
            throw new InvalidArgumentException('An end date is a calendar day, YYYY-MM-DD.');
        }

        return $date;
    }

    private static function text(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
