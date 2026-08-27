<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientTimeEntry;
use DomainException;

final class AgreementBillingRateResolver
{
    /** @return array{amount:int,currency:string} */
    public function resolve(ClientTimeEntry $entry): array
    {
        $workedOn = $entry->worked_on->toDateString();
        $agreements = ClientAgreement::query()
            ->where('workspace_id', $entry->workspace_id)
            ->where('client_company_id', $entry->client_company_id)
            // An agreement that has since ended was still in force on the day the
            // work happened, so eligibility is the effective date range, not the
            // current lifecycle status. Only a draft was never in force at all.
            ->where('status', '!=', 'draft')
            ->where(function ($query) use ($entry): void {
                $query->whereNull('client_project_id')->orWhere('client_project_id', $entry->client_project_id);
            })
            ->where(function ($query) use ($workedOn): void {
                $query->whereNull('starts_on')->orWhereDate('starts_on', '<=', $workedOn);
            })
            ->where(function ($query) use ($workedOn): void {
                $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $workedOn);
            })
            ->get()
            ->sort(function (ClientAgreement $left, ClientAgreement $right) use ($entry): int {
                $leftSpecific = $left->client_project_id === $entry->client_project_id;
                $rightSpecific = $right->client_project_id === $entry->client_project_id;
                if ($leftSpecific !== $rightSpecific) {
                    return $leftSpecific ? -1 : 1;
                }
                // Prefer an agreement still running over one already ended, then
                // the latest start, so a renewal wins over the term it replaced.
                //
                // "Still running" is `active`, not "not terminated": the status
                // filter above widened to admit paused and expired agreements
                // because they were in force on the work date, and an expired
                // one counting as open let it beat a live agreement on a later
                // start and stamp its rate on the entry.
                $leftOpen = $left->status === 'active';
                $rightOpen = $right->status === 'active';
                if ($leftOpen !== $rightOpen) {
                    return $leftOpen ? -1 : 1;
                }

                $leftStart = $left->starts_on?->format('Y-m-d') ?? '';
                $rightStart = $right->starts_on?->format('Y-m-d') ?? '';

                return $rightStart <=> $leftStart ?: $right->id <=> $left->id;
            });
        $agreement = $agreements->first();
        if (! $agreement instanceof ClientAgreement || $agreement->hourly_rate_amount === null) {
            throw new DomainException("No active billing rate applies to time entry {$entry->public_id}.");
        }

        return [
            'amount' => $agreement->hourly_rate_amount,
            'currency' => MoneyService::currency($agreement->currency),
        ];
    }
}
