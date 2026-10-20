<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Support\Billing\AgreementHandover;
use Illuminate\Database\Eloquent\Builder;

final class AgreementHandoverRepository
{
    /**
     * Load complete company histories once per batch, including future successors
     * outside the generator's selection window. Project retainers run independently.
     *
     * @param  list<array{workspace_id: int, client_company_id: int}>  $scopes
     * @return array<int, AgreementHandover> keyed by outgoing agreement id
     */
    public function forCompanies(array $scopes): array
    {
        if ($scopes === []) {
            return [];
        }
        $agreements = ClientAgreement::query()
            ->whereIn('status', ['active', 'paused', 'terminated', 'expired'])
            ->where(function (Builder $query) use ($scopes): void {
                foreach ($scopes as $scope) {
                    $query->orWhere(fn (Builder $company) => $company
                        ->where('workspace_id', $scope['workspace_id'])
                        ->where('client_company_id', $scope['client_company_id']));
                }
            })
            ->orderBy('starts_on')->orderBy('id')
            ->get(['id', 'public_id', 'workspace_id', 'client_company_id', 'client_project_id', 'starts_on', 'ends_on']);

        $previous = [];
        $handovers = [];
        foreach ($agreements as $agreement) {
            $key = $agreement->workspace_id.':'.$agreement->client_company_id.':'.$agreement->client_project_id;
            if (isset($previous[$key])) {
                $outgoing = $previous[$key];
                $handovers[$outgoing->id] = new AgreementHandover(
                    $agreement->public_id, $agreement->starts_on->toDateString(), $outgoing->ends_on?->toDateString(),
                );
            }
            $previous[$key] = $agreement;
        }

        return $handovers;
    }

    public function forAgreement(ClientAgreement $agreement): ?AgreementHandover
    {
        return $this->forCompanies([['workspace_id' => $agreement->workspace_id, 'client_company_id' => $agreement->client_company_id]])[$agreement->id] ?? null;
    }
}
