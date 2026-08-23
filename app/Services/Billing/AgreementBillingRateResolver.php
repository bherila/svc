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
            ->where('status', 'active')
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
