<?php

declare(strict_types=1);

namespace App\Services\Revenue;

use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Revenue\RevenueDashboard;
use App\Support\Revenue\RevenueQuery;

/**
 * The revenue props of the Dashboard and of Client Overview, with every link
 * finished here from the viewer's own route family. The figures come from
 * {@see RevenueReadService}, the same read behind REST and MCP, so the three
 * cannot disagree; nothing is computed here or in the browser.
 *
 * Callers must have shown the viewer manages the workspace: the read service
 * refuses anyone else with a not-found, and the controllers do not call this
 * for anyone else at all, so a member's page has no revenue prop.
 */
final class RevenuePagePresenter
{
    public function __construct(private readonly RevenueReadService $revenue) {}

    /**
     * @param  array<array-key, mixed>  $input  the request's query string
     * @return array<string, mixed>
     */
    public function dashboard(User $user, Workspace $workspace, array $input): array
    {
        $query = RevenueQuery::fromInput($input);
        $read = $this->revenue->dashboard($user, $workspace, $query);
        $summary = $read['summary'];
        $clientHref = fn (string $clientId): string => route('clients.show', [$workspace, $clientId], absolute: false);

        return [
            'toggle_href' => route('workspaces.dashboard', $workspace, absolute: false),
            'filters' => $query->toggles(),
            'assumptions' => $this->assumptions($summary),
            'kpis' => RevenueDashboard::kpis($summary),
            'months' => RevenueDashboard::months(
                $read['months'],
                fn (string $month): string => route('workspaces.invoices', [$workspace, 'month' => $month], absolute: false),
                $clientHref,
            ),
            'at_risk' => RevenueDashboard::atRisk(
                $summary['changes']['ending'],
                $clientHref,
                fn (string $clientId, string $agreementId): string => route('clients.agreement', [$workspace, $clientId, $agreementId], absolute: false),
            ),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $input  the request's query string
     * @return array<string, mixed>
     */
    public function overview(User $user, Workspace $workspace, ClientCompany $company, array $input): array
    {
        $query = RevenueQuery::fromInput($input, (string) $company->public_id);
        $read = $this->revenue->clientOverview($user, $workspace, $query, withLedgers: false);
        $summary = $read['summary'];
        $agreementHref = fn (string $agreementId): string => route('clients.agreement', [$workspace, $company, $agreementId], absolute: false);
        $kpis = RevenueDashboard::kpis($summary);

        return [
            'toggle_href' => route('clients.show', [$workspace, $company], absolute: false),
            'filters' => $query->toggles(),
            'assumptions' => $this->assumptions($summary),
            'kpis' => ['mrr' => $read['client']['mrr'] ?? 0, ...$kpis],
            'months' => RevenueDashboard::months(
                $read['months'],
                fn (string $month): string => route('clients.invoices', [$workspace, $company, 'month' => $month], absolute: false),
                fn (string $clientId): string => route('clients.show', [$workspace, $clientId], absolute: false),
            ),
            'agreements' => RevenueDashboard::agreementCards($read['agreements'], $agreementHref),
        ];
    }

    /**
     * Retainer use this period, one row per agreement in force. It reads the
     * capacity ledgers, which cost queries per agreement, so the page asks for
     * it after its first paint.
     *
     * @return list<array<string, mixed>>
     */
    public function thisPeriod(User $user, Workspace $workspace, ClientCompany $company): array
    {
        $read = $this->revenue->clients($user, $workspace, new RevenueQuery(clientId: (string) $company->public_id));

        return RevenueDashboard::thisPeriod(
            $read['clients'][0]['agreements'] ?? [],
            fn (string $agreementId): string => route('clients.agreement', [$workspace, $company, $agreementId], absolute: false),
        );
    }

    /**
     * What the figures were computed under, for the one line that tells a
     * reader the forecast is on the invoiced basis whatever the toggle says.
     *
     * @param  array<string, mixed>  $summary
     * @return array{as_of: string, basis: string, projection_basis: string, currency: string|null}
     */
    private function assumptions(array $summary): array
    {
        $assumptions = $summary['assumptions'];

        return [
            'as_of' => $assumptions['as_of'],
            'basis' => $assumptions['basis'],
            'projection_basis' => $assumptions['projection_basis'],
            'currency' => $assumptions['currency'],
        ];
    }
}
