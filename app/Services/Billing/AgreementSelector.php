<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Support\Billing\BillingCadence;
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
    /**
     * The agreement in force, falling back to the most recent one.
     *
     * The fallback is what lets a terminated client still receive a closing
     * invoice for work done in the final period.
     */
    public function agreementForInvoiceGeneration(ClientCompany $company): ClientAgreement
    {
        $agreement = $company->activeAgreement() ?? $company->mostRecentAgreement();
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
        $now = CarbonImmutable::now();

        $agreements = $company->agreements()
            ->where('status', '!=', 'draft')
            ->where(function ($query) use ($now): void {
                $query->where('starts_on', '<=', $now->toDateString())
                    ->orWhere(function ($query) use ($now): void {
                        $query->where('billing_cadence', '!=', BillingCadence::Monthly->value)
                            ->where('starts_on', '<=', $now->addMonth()->toDateString());
                    });
            })
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
     * Ties on start date are broken by id so that two agreements activated the
     * same day still have a defined order; without that the walk could bill one
     * segment twice and the other never.
     *
     * @param  Collection<int, ClientAgreement>  $agreements
     */
    public function successorAgreementForGeneration(Collection $agreements, ClientAgreement $agreement): ?ClientAgreement
    {
        $startsOn = $agreement->starts_on;
        if ($startsOn === null) {
            return null;
        }

        return $agreements->first(function (ClientAgreement $candidate) use ($agreement, $startsOn): bool {
            if ($candidate->id === $agreement->id || $candidate->starts_on === null) {
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
            ->where('status', '!=', 'draft')
            ->where('starts_on', '<=', $date->toDateString())
            ->where(function ($query) use ($date): void {
                $query->whereNull('ends_on')->orWhere('ends_on', '>=', $date->toDateString());
            })
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->first();
    }
}
