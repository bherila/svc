<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Support\WorkspaceClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Which agreement an invoice should be generated against.
 *
 * A company's billing history is a sequence of agreement segments, not one
 * agreement: terms change, and each segment owns the cycles that fall inside
 * it. Generation walks every segment that can still produce an invoice, so this
 * deliberately returns terminated agreements too - trailing post-termination
 * work still has to be billed against the terms it was performed under.
 *
 * `starts_on` and `ends_on` are used directly rather than through the engine's
 * `active_date` / `termination_date` accessors, which exist for reading a
 * loaded model and cannot appear in a query.
 */
final class AgreementSelector
{
    public function __construct(private readonly WorkspaceClock $clock = new WorkspaceClock) {}

    /**
     * The agreement in force, falling back to the most recent one.
     *
     * The fallback is what lets a terminated client still receive a closing
     * invoice for work done in the final period.
     *
     * Every selector here carries the company's `workspace_id` explicitly. A
     * company id is globally unique, so an agreement row holding this company's
     * id under another tenant's workspace is reachable through the foreign key
     * alone - and the schema has no composite constraint to stop one existing.
     * The explicit-agreement path validates both keys; these automatic paths
     * never passed through it, so bulk and date-based generation could bill a
     * client on another tenant's terms.
     */
    public function agreementForInvoiceGeneration(ClientCompany $company): ClientAgreement
    {
        $agreement = $company->activeAgreement($this->clock->today($company->workspace)) ?? $company->mostRecentAgreement();
        if (! $agreement instanceof ClientAgreement) {
            throw new RuntimeException('No agreement found for this client company.');
        }

        return $agreement;
    }

    /**
     * Every historical agreement segment that can still produce invoices.
     *
     * Non-monthly agreements are included a month before they start: a cadence
     * cycle is billed in advance, so the invoice for a quarter that begins next
     * month is generated this month.
     *
     * @return Collection<int, ClientAgreement>
     */
    public function agreementsForInvoiceGeneration(ClientCompany $company): Collection
    {
        $now = $this->clock->now($company->workspace);
        $selectionCeiling = $now->addMonthNoOverflow()->endOfDay();

        $agreements = $company->agreements()
            ->where('workspace_id', $company->workspace_id)
            ->whereIn('status', ['active', 'paused', 'terminated', 'expired'])
            // Every cadence bills its opening retainer in advance, monthly
            // included, so an agreement starting next month is selected now.
            // Excluding monthly here made exactly those first invoices late
            // while quarterly and annual ones arrived on time.
            ->where('starts_on', '<=', $selectionCeiling)
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get();

        if ($agreements->isEmpty()) {
            throw new RuntimeException('No agreement found for this client company.');
        }

        return $agreements;
    }

    /**
     * The segment that takes over from this one, if any.
     *
     * Only an agreement covering the same project scope can replace another.
     * Two project retainers under one company run concurrently; treating the
     * earlier agreement from an unrelated project as a successor truncates the
     * outgoing segment and leaves its gap-period work unbilled.
     *
     * Ties on start date within one scope are broken by id so that two genuine
     * replacements activated the same day still have a defined order.
     *
     * @param  Collection<int, ClientAgreement>  $agreements
     */
    public function successorAgreementForGeneration(Collection $agreements, ClientAgreement $agreement): ?ClientAgreement
    {
        $startsOn = $agreement->starts_on;

        return $agreements->first(function (ClientAgreement $candidate) use ($agreement, $startsOn): bool {
            // No null guard on either side: `starts_on` is `NOT NULL` (#147).
            if ($candidate->id === $agreement->id
                || $candidate->client_project_id !== $agreement->client_project_id) {
                return false;
            }

            $candidateStart = $candidate->starts_on->startOfDay();

            return $candidateStart->gt($startsOn->startOfDay())
                || ($candidateStart->eq($startsOn->startOfDay()) && $candidate->id > $agreement->id);
        });
    }

    /**
     * The agreement whose term covers a given date.
     */
    public function agreementCoveringDate(ClientCompany $company, CarbonImmutable $date): ?ClientAgreement
    {
        return $company->agreements()
            ->where('workspace_id', $company->workspace_id)
            ->whereIn('status', ['active', 'paused', 'terminated', 'expired'])
            ->where('starts_on', '<=', $date->toDateString())
            ->where(function ($query) use ($date): void {
                $query->whereNull('ends_on')->orWhere('ends_on', '>=', $date->toDateString());
            })
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->first();
    }
}
