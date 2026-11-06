<?php

namespace App\Services\AgentApi\Operations;

/** Code-owned accounting schemas; the public contract is regenerated from these declarations. */
final class AccountingOperations
{
    public const array ROUTES = [
        'finance.invoice_lines.list' => ['finance.invoice-lines.index', '/workspaces/{workspace}/accounting/invoice-lines', [200]],
        'finance.cash_income.list' => ['finance.cash-income.index', '/workspaces/{workspace}/accounting/cash-income', [200]],
        'finance.income.yearly' => ['finance.income.yearly', '/workspaces/{workspace}/accounting/income', [200]],
    ];

    /** @return array<string, array{method: string, scopes: list<string>, description: string, parameters: list<string|array<string, mixed>>, errors: array<int, string>}> */
    public static function documentation(): array
    {
        $common = ['method' => 'GET', 'scopes' => ['finance:read'], 'parameters' => ['WorkspaceId'], 'errors' => [
            403 => 'A workspace owner or admin and finance:read are required.',
            404 => 'The workspace is not visible.', 422 => 'Invalid date, year, page size or opaque cursor.',
        ]];
        $operations = [];
        foreach ([
            'finance.invoice_lines.list' => 'Issued invoice line accounting, with signed net, tax, effective tax rate, taxable flag, readable line type, service period and client/project public IDs. Original records never change. Corrections append a reversal naming the previous record and a replacement; voids and reopens append reversals. Historical invoices are projected synchronously from correction evidence. The effective date remains the original issue date, so a correction restates its issue period.',
            'finance.cash_income.list' => 'Cash income by payment received date, manual refund/reversal UTC recording date, and processor movement effective date. Reuses the existing finance payment query and signed processor movements; payouts transfer already received money and are not income. Known fees are separate; no fees or missing historical refund dates are invented. Payment rows expose unattributed_refund_amount for cumulative refunds without dated ledger evidence. Manager only.',
            'finance.income.yearly' => 'Calendar-year income summary: twelve monthly rows of invoiced net and tax, cash received, refunds and reversals, known fees, dispute movements and expenses passed through. Corrections restate the original issue period. Synchronously computed in bounded chunks, no job or external processor call. JSON by default; REST format=csv streams the same rows. MCP returns JSON. All amounts are integer minor units in the workspace currency.',
        ] as $id => $description) {
            $entry = $common;
            $entry['description'] = $description;
            if ($id === 'finance.income.yearly') {
                $entry['parameters'][] = self::parameter('year', ['type' => 'integer', 'minimum' => 1900, 'maximum' => 9999], true);
                $entry['parameters'][] = self::parameter('format', ['type' => 'string', 'enum' => ['json', 'csv'], 'default' => 'json']);
            } else {
                $entry['description'] .= ' At most 100 records, ordered by change timestamp then stable ID; follow meta.next_cursor. For the next pass send the opaque meta.updated_since as updated_since. It carries a timestamp and ID tiebreaker and is bound to this workspace and operation. Watermarks lag by 120 seconds, as existing finance feeds do. from/to filter effective dates on full reads; sync without date filters, then filter locally.';
                $entry['parameters'] = [...$entry['parameters'], self::parameter('limit', ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50]),
                    self::parameter('cursor', ['type' => 'string', 'maxLength' => 2048]), self::parameter('updated_since', ['type' => 'string', 'maxLength' => 2048]),
                    self::parameter('from', ['type' => 'string', 'format' => 'date']), self::parameter('to', ['type' => 'string', 'format' => 'date'])];
            }
            $operations[$id] = $entry;
        }

        return $operations;
    }

    /** @return array<string, mixed> */
    public static function output(string $id): array
    {
        if ($id === 'finance.income.yearly') {
            $fields = ['month' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}$']];
            foreach (['invoiced_net', 'invoiced_tax', 'cash_received', 'refunds', 'refund_reversals', 'fees', 'expenses_passed_through', 'dispute_movements', 'net_cash', 'unattributed_refunds'] as $name) {
                $fields[$name] = ['type' => 'integer'];
            }

            return self::object(['data' => self::object(['year' => ['type' => 'integer'], 'months' => ['type' => 'array', 'minItems' => 12, 'maxItems' => 12, 'items' => self::object($fields)]])]);
        }
        $fields = $id === 'finance.invoice_lines.list' ? self::lineFields() : self::cashFields();
        $fields['updated_at'] = ['type' => ['string', 'null'], 'format' => 'date-time'];

        return self::object(['data' => ['type' => 'array', 'maxItems' => 100, 'items' => self::object($fields)], 'meta' => self::object([
            'next_cursor' => ['type' => ['string', 'null']], 'watermark' => ['type' => 'string', 'format' => 'date-time'], 'updated_since' => ['type' => 'string'],
        ])]);
    }

    /** @return array<string, array<string, mixed>> */
    private static function lineFields(): array
    {
        $fields = [];
        foreach (['id', 'invoice_id', 'line_id', 'client_id'] as $name) {
            $fields[$name] = ['type' => 'string', 'format' => 'uuid'];
        }
        foreach (['project_id', 'reverses_id'] as $name) {
            $fields[$name] = ['type' => ['string', 'null'], 'format' => 'uuid'];
        }
        foreach (['kind', 'currency', 'line_type', 'line_type_label', 'description', 'quantity'] as $name) {
            $fields[$name] = ['type' => 'string'];
        }
        foreach (['net_amount', 'tax_amount', 'total_amount', 'unit_amount'] as $name) {
            $fields[$name] = ['type' => 'integer'];
        }
        $fields['tax_rate'] = ['type' => ['number', 'null']];
        $fields['taxable'] = ['type' => 'boolean'];
        foreach (['effective_on', 'service_period_start', 'service_period_end'] as $name) {
            $fields[$name] = ['type' => ['string', 'null'], 'format' => 'date'];
        }

        return $fields;
    }

    /** @return array<string, array<string, mixed>> */
    private static function cashFields(): array
    {
        $fields = ['version' => ['type' => 'string'], 'id' => ['type' => 'string'], 'source_id' => ['type' => 'string', 'format' => 'uuid'],
            'kind' => ['type' => 'string'], 'payment_id' => ['type' => 'string', 'format' => 'uuid'],
            'reverses_id' => ['type' => ['string', 'null'], 'format' => 'uuid'],
            'currency' => ['type' => 'string'], 'effective_on' => ['type' => ['string', 'null'], 'format' => 'date']];
        foreach (['amount', 'fee_amount', 'net_amount', 'unattributed_refund_amount'] as $name) {
            $fields[$name] = ['type' => 'integer'];
        }

        return $fields;
    }

    /** @param array<string, mixed> $fields
     * @return array<string, mixed> */
    private static function object(array $fields): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($fields), 'properties' => $fields];
    }

    /** @param array<string, mixed> $schema
     * @return array<string, mixed> */
    private static function parameter(string $name, array $schema, bool $required = false): array
    {
        return ['name' => $name, 'in' => 'query', 'required' => $required, 'schema' => $schema];
    }
}
