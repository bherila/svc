<?php

namespace App\Services\Billing;

use App\Models\ClientCompany;
use App\Models\ClientTimeEntry;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Refuses to bill time through a company/project ownership chain that disagrees.
 *
 * The schema carries independent foreign keys for a time entry's company and
 * project. Either one can therefore be valid while the pair is not. Filtering
 * the bad row would silently underbill; accepting it can charge one company's
 * work to another. Billing stops instead, while read-only callers can use the
 * predicate to withhold a total they cannot state honestly.
 */
final class TimeEntryProjectChainGuard
{
    public const FAILURE_MESSAGE = 'Billing stopped because a time entry points to a project outside this client company. Correct the entry before retrying.';

    public function companyProjectChainsAgree(ClientCompany $company): bool
    {
        $insideWorkspace = $this->projectChainsAgree(
            $company,
            ClientTimeEntry::query()
                ->where('workspace_id', $company->workspace_id)
                ->where('client_company_id', $company->id),
        );

        // A malformed row can carry this company's globally unique id while
        // claiming another workspace. Keep that integrity scan explicit: a
        // normal workspace scope alone would hide the row and silently
        // underbill it, while an unqualified company-id query would violate
        // the tenant-query invariant this guard exists to enforce.
        $outsideWorkspace = $this->projectChainsAgree(
            $company,
            ClientTimeEntry::query()
                ->where('workspace_id', '!=', $company->workspace_id)
                ->where('client_company_id', $company->id),
        );

        return $insideWorkspace && $outsideWorkspace;
    }

    public function assertCompanyProjectChainsAgree(ClientCompany $company): void
    {
        if (! $this->companyProjectChainsAgree($company)) {
            throw new DomainException(self::FAILURE_MESSAGE);
        }
    }

    /**
     * @param  Builder<ClientTimeEntry>  $entries
     */
    public function projectChainsAgree(ClientCompany $company, Builder $entries): bool
    {
        return ! (clone $entries)
            ->where(function (Builder $entry) use ($company): void {
                $entry
                    ->where('client_time_entries.workspace_id', '!=', $company->workspace_id)
                    ->orWhere('client_time_entries.client_company_id', '!=', $company->id)
                    ->orWhereDoesntHave('project', fn (Builder $project): Builder => $project
                        ->where('workspace_id', $company->workspace_id)
                        ->where('client_company_id', $company->id));
            })
            ->exists();
    }

    /**
     * @param  Builder<ClientTimeEntry>  $entries
     */
    public function assertProjectChainsAgree(ClientCompany $company, Builder $entries): void
    {
        if (! $this->projectChainsAgree($company, $entries)) {
            throw new DomainException(self::FAILURE_MESSAGE);
        }
    }
}
