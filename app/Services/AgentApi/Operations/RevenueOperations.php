<?php

namespace App\Services\AgentApi\Operations;

use App\Services\Revenue\RevenueSummaries;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\Revenue\RevenueAssumptions;
use App\Support\Revenue\RevenueBasis;

/**
 * The revenue planning reads (docs/ux-overhaul.md section 13): REST bindings
 * and the contract prose their MCP tools share. Workspace managers only,
 * under `revenue:read`, which `finance:read` does not imply.
 */
final class RevenueOperations
{
    private const string ROOT = '/workspaces/{workspace}/revenue';

    public const array ROUTES = [
        'revenue.summary' => ['revenue.summary', self::ROOT.'/summary', [200]],
        'revenue.monthly' => ['revenue.monthly', self::ROOT.'/monthly', [200]],
        'revenue.projection' => ['revenue.projection', self::ROOT.'/projection', [200]],
        'revenue.clients' => ['revenue.clients', self::ROOT.'/clients', [200]],
    ];

    /** Operation id => [MCP and controller handler, title, response component, query parameters]. */
    public const array DEFINITIONS = [
        'revenue.summary' => ['summary', 'Read revenue key figures', 'RevenueSummaryResponse', ['basis', 'include_expenses', 'include_projected_overage', 'client_id']],
        'revenue.monthly' => ['monthly', 'Read monthly revenue by client', 'RevenueMonthlyResponse', ['basis', 'include_expenses', 'include_projected_overage', 'band', 'client_id']],
        'revenue.projection' => ['projection', 'Read the per-agreement revenue projection', 'RevenueProjectionResponse', ['include_projected_overage', 'client_id']],
        'revenue.clients' => ['clients', 'Read the client revenue roster', 'RevenueClientsResponse', ['basis', 'include_expenses', 'client_id']],
    ];

    /** @return array<string, array{method: string, scopes: list<string>, summary: string, description: string, parameters: list<string|array<string, mixed>>, response: string, response_description: string, errors: array<int, string>}> */
    public static function documentation(): array
    {
        $shared = 'Workspace owners/admins only; anyone else is told the workspace is not found. Amounts are integer minor units of the workspace currency. Revenue excludes tax; receivables are balances owed, tax included. The invoiced basis counts issued, partially paid, paid and disputed invoices (never draft or void), placed by service month (service_period_end, else the issue date); a retainer or recurring-item line, sold in advance, is placed on its own date. Corrections and credits net in the month of the invoice they apply to. The collected basis counts payments net of refunds by received date, spread over the paid invoice\'s bands before tax. Bands: recurring (retainer and recurring items), overage_and_other (additional hours, milestones, subcontractor, adjustments, credits and the rest), expenses. The projection is always invoiced-basis: signed agreements count only from their start date, stop at their end or termination with no renewal assumed, place recurring items at their own cadence, and never project expenses. Projected overage is each agreement\'s trailing '.RevenueAssumptions::OVERAGE_WINDOW_MONTHS.'-month average of billed overage amount, zero below '.RevenueAssumptions::MINIMUM_HISTORY_MONTHS.' months of history. Every response carries an assumptions block (as_of, basis, overage window, minimum history, method_version). Computed in the request; nothing is stored.';
        $descriptions = [
            'revenue.summary' => 'Key figures: year to date (January 1 to today) and trailing twelve months (twelve full months plus the month to date), each against the same span a year earlier; the next twelve full months contracted, with projected overage when include_projected_overage is true; the current month\'s actual so far and what remains contracted; open receivables with the overdue amount and count; and the agreements ending (with the monthly amount that drops) and starting in the next twelve months. client_id narrows every figure to one client.',
            'revenue.monthly' => 'Per month and per client, from twelve months back to twelve months ahead: kind is actual, current or projected. Actual bands (recurring, overage_and_other, expenses) come from the basis; contracted and projected_overage come from the projection, so the current month shows actuals so far plus what remains contracted. band narrows each row to one band (recurring with contracted, overage_and_other with projected overage, or expenses); a figure outside the band, or switched off by include_expenses or include_projected_overage, is null. client_id narrows to one client.',
            'revenue.projection' => 'Per-agreement rows for the current month and the twelve full months after it: terms, retainer, each recurring item with its next charge date, the overage method and history, and each month\'s contracted retainer, recurring items and projected overage. A charge already on a charged invoice is actual and left out. client_id narrows to one client.',
            'revenue.clients' => 'The client roster: per client its MRR (recurring revenue normalised to a month: quarterly ÷ 3, semi-annual ÷ 6, annual ÷ 12, one-time excluded), year to date, trailing twelve months with a twelve-point series of the full months, open and overdue amounts, and the health of each agreement in force: retainer hours used against included this billing period from the capacity ledger, whether it closes over its retainer, and whether it ends within '.RevenueAssumptions::ENDING_SOON_DAYS.' days. Totals for the roster are included. client_id narrows to one client.',
        ];
        $parameters = [
            'basis' => ['name' => 'basis', 'in' => 'query', 'description' => 'Which money past months count.', 'schema' => ['type' => 'string', 'enum' => array_map(static fn (RevenueBasis $basis): string => $basis->value, RevenueBasis::cases()), 'default' => RevenueBasis::Invoiced->value]],
            'include_expenses' => ['name' => 'include_expenses', 'in' => 'query', 'description' => 'Count pass-through expenses.', 'schema' => ['type' => 'boolean', 'default' => true]],
            'include_projected_overage' => ['name' => 'include_projected_overage', 'in' => 'query', 'description' => 'Add the projected overage band.', 'schema' => ['type' => 'boolean', 'default' => false]],
            'band' => ['name' => 'band', 'in' => 'query', 'description' => 'Narrow each row to one band.', 'schema' => ['type' => 'string', 'enum' => RevenueSummaries::BANDS, 'default' => RevenueSummaries::BAND_ALL]],
            'client_id' => ['name' => 'client_id', 'in' => 'query', 'description' => 'One client of the workspace.', 'schema' => ['type' => 'string', 'format' => 'uuid']],
        ];
        $errors = [
            401 => 'Not signed in, or the token is missing, expired or revoked.',
            403 => 'The token lacks revenue:read.',
            404 => 'The workspace is not visible to the caller, the caller is not a workspace owner or admin, or client_id names no client of this workspace.',
            422 => 'A query parameter is invalid (errors names it).',
            429 => 'Too many requests (retry after Retry-After).',
        ];

        $result = [];
        foreach (self::DEFINITIONS as $id => [, $summary, $response, $query]) {
            $result[$id] = [
                'method' => 'GET',
                'scopes' => [AgentApiScopes::REVENUE_READ],
                'summary' => $summary,
                'description' => $descriptions[$id].' '.$shared,
                'parameters' => ['WorkspaceId', ...array_map(static fn (string $name): array => $parameters[$name], $query)],
                'response' => $response,
                'response_description' => $summary,
                'errors' => $errors,
            ];
        }

        return $result;
    }
}
