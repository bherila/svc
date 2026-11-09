<?php

namespace App\Services\Engagement;

use App\Http\Middleware\EnsureServicePackagesEnabled;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ServicePackageEnrollment;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Portal\PortalOverviewQuery;
use App\Services\Authorization\CompanyContacts;
use App\Support\Engagement\Cancellation\CancellationRefusal;
use App\Support\Engagement\Cancellation\CancellationRules;
use App\Support\Engagement\Cancellation\ScheduledTerminationSource;
use App\Support\Packages\CancellationMode;

/**
 * The portal's cancellation panel for one agreement (CAN-2, CAN-3, CAN-4,
 * EDGE-9, EDGE-11), finished on the server: the terms, the end dates offered
 * today, what is pending or scheduled, what the client still owes, and - for
 * a company-wide contact only - the URLs that cancel, withdraw and undo.
 * Everyone else who may view the portal reads the same panel without them.
 *
 * Null when there is nothing to show: the feature is off, the agreement is
 * not one the portal shows, or it offers no cancellation and none is pending
 * or scheduled.
 */
final class PortalCancellationPanel
{
    public function __construct(
        private readonly CancellationStates $states,
        private readonly CompanyContacts $contacts,
        private readonly PortalOverviewQuery $overview,
    ) {}

    /** @return array<string, mixed>|null */
    public function for(User $viewer, ClientCompany $company, ClientAgreement $agreement): ?array
    {
        if (! EnsureServicePackagesEnabled::enabled() || ! self::shownInPortal($company, $agreement)) {
            return null;
        }
        $workspace = Workspace::query()->findOrFail($company->workspace_id);
        $state = $this->states->one($workspace, $agreement);
        $client = $state->forClient();
        if ($state->terms->mode === CancellationMode::None && $state->pending === null && $state->scheduled === null) {
            return null;
        }
        $acting = $this->contacts->isCompanyContact($company, $viewer);
        $refusal = CancellationRules::clientRefusal($state->terms->mode, (string) $agreement->status, $state->terms->recurring(), $state->scheduled !== null, $state->pending !== null)
            ?? ($client['offered_end_dates'] === [] ? CancellationRefusal::NoDatesOffered : null);
        $undoable = $acting && $state->scheduled !== null && CancellationRules::undoRefusal(
            $state->scheduled->terminationStatus(), $state->scheduled->terminationSource(), $state->terms->mode, false, $state->today, $state->scheduled->ends_on,
        ) === null;

        return [
            ...$client,
            'minimum_term_ends_on' => $state->terms->minimumTermMonths === null ? null
                : $state->terms->startsOn->addMonthsNoOverflow($state->terms->minimumTermMonths)->subDay()->toDateString(),
            'provider_name' => $workspace->name,
            // EDGE-11: shown, never a reason to refuse; it stays owed.
            'balance' => $acting ? $this->overview->balance($company, $viewer) : null,
            'cancel_url' => $acting && $refusal === null ? route('portal.agreements.cancellation.store', [$company, $agreement], absolute: false) : null,
            // Why this viewer cannot cancel now, when the reason is the agreement's, for the panel to say.
            'cancel_refusal' => $refusal === null || $state->pending !== null || $state->scheduled !== null ? null
                : ['code' => $refusal->value, 'message' => $refusal->message()],
            'withdraw_url' => $acting && $state->pending !== null ? route('portal.agreements.cancellation.withdraw', [$company, $agreement], absolute: false) : null,
            'undo_url' => $undoable ? route('portal.agreements.cancellation.undo', [$company, $agreement], absolute: false) : null,
            'cancelled_by_client' => $state->scheduled !== null && $state->scheduled->terminationSource() === ScheduledTerminationSource::SelfServe,
        ];
    }

    /** The portal page a client reads this agreement on: its service's, for a package agreement, or its own. */
    public function pageUrl(ClientCompany $company, ClientAgreement $agreement, bool $absolute = false): string
    {
        $enrollment = ServicePackageEnrollment::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_agreement_id', $agreement->id)
            ->value('public_id');

        return is_string($enrollment)
            ? route('portal.services.show', [$company, $enrollment], absolute: $absolute)
            : route('portal.agreement', [$company, $agreement], absolute: $absolute);
    }

    /** As the portal's agreement page decides: this company's, shown to the client, and started. */
    public static function shownInPortal(ClientCompany $company, ClientAgreement $agreement): bool
    {
        return (int) $agreement->workspace_id === (int) $company->workspace_id
            && (int) $agreement->client_company_id === (int) $company->id
            && (bool) $agreement->is_visible_to_client
            && $agreement->status !== 'draft';
    }
}
