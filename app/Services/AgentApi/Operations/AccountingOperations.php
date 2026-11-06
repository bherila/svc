<?php

namespace App\Services\AgentApi\Operations;

use App\Support\Accounting\AccountingFeedQuery;
use App\Support\Accounting\CashEntryType;
use App\Support\Accounting\LineEntryType;
use App\Support\AgentApi\ChangeWindow;

/**
 * The accounting reads for bookkeeping and tax software (docs/ux-overhaul.md
 * section 13): REST bindings and the contract prose their MCP tools share.
 * Workspace owners and admins only, under `finance:read`.
 */
final class AccountingOperations
{
    public const int YEAR_MIN = 2000;

    public const int YEAR_MAX = 2100;

    public const array ROUTES = [
        'finance.invoice_lines.list' => ['invoice-lines.index', '/workspaces/{workspace}/invoice-lines', [200]],
        'finance.cash_income.list' => ['cash-income.index', '/workspaces/{workspace}/cash-income', [200]],
        'finance.income_export.get' => ['income-export.show', '/workspaces/{workspace}/income-export', [200]],
        'finance.income_export.csv' => ['income-export.csv', '/workspaces/{workspace}/income-export/csv', [200]],
    ];

    /** Operation id => [MCP and controller handler, title, response component, query parameters]; the MCP tools. */
    public const array DEFINITIONS = [
        'finance.invoice_lines.list' => ['invoiceLines', 'List invoice line entries with tax broken out', 'FinanceInvoiceLineEntryListResponse', ['entry_type', 'client_id', 'invoice_id', 'from', 'to', 'per_page', 'UpdatedSince', 'Watermark', 'Cursor']],
        'finance.cash_income.list' => ['cashIncome', 'List cash-basis income entries', 'FinanceCashEntryListResponse', ['entry_type', 'client_id', 'invoice_id', 'from', 'to', 'per_page', 'UpdatedSince', 'Watermark', 'Cursor']],
        'finance.income_export.get' => ['incomeExport', 'Read a calendar year of income by month', 'FinanceIncomeExportResponse', ['year']],
    ];

    /** @return array<string, array{method: string, scopes: list<string>, summary: string, description: string, parameters: list<string|array<string, mixed>>, response?: string, response_description?: string, binary?: array{string, string}, errors: array<int, string>}> */
    public static function documentation(): array
    {
        $lag = (string) ChangeWindow::LAG_SECONDS;
        $shared = 'Workspace owners/admins only: a member who is not an owner or admin, including the holder of a Sanctum finance token, is refused with 403. Amounts are signed integer minor units of the workspace currency (a workspace has one). Computed in the request; nothing is stored.';
        $feed = 'Newest entry first (when it was recorded, then its source and ids), '.AccountingFeedQuery::DEFAULT_PER_PAGE.' a page unless per_page is (at most '.AccountingFeedQuery::MAX_PER_PAGE.'). Always a cursor listing inside a snapshot watermark: the first page of a pass fixes the watermark (now less '.$lag.' seconds, or an earlier time given) and every page returns it in meta; follow meta.next_cursor until it is null, then start the next pass with updated_since set to that watermark (inclusive; starting a few seconds earlier is harmless). Consecutive passes overlap by design: deduplicate by id. A cursor from another workspace, query or watermark is refused with 422.';
        $descriptions = [
            'finance.invoice_lines.list' => 'The lines of every issued invoice with tax broken out, as an append-only ledger. An entry_type invoice_line entry is a line as it was issued: net_amount (before its own tax), tax_amount, total_amount, taxable, tax_rate (the effective tax over the net to four places, since a line stores a tax amount and not a rate; null when untaxed), line_type (the stored token, to map from) and line_type_label (to display), the invoice\'s service period, and the client_id and project_id (null for a line with no project) public ids. An issued line is never rewritten in this feed. A correction to an unpaid issued invoice (invoices.correct) arrives as a new entry_type correction whose signed amounts are the change it made (a wording-only correction changes no money and arrives as nothing), and a void as one entry_type void per line with the line\'s amounts negated. Each names the invoice_line entry it reverses in reverses_entry_id, and a correction its correction_id (invoices.corrections.get). Summing the entries of one line gives what the line stands for now. A credit is a negative line of the invoice it applies to. effective_on is the invoice\'s issue date for a line, and the day it was recorded in the workspace calendar for a correction or a void. A draft is no entry, and an invoice reopened for reissue is a draft until it is issued again. '.$feed.' Entries never change, so entry_type, client_id, invoice_id and from/to (on effective_on) may all be combined with updated_since.',
            'finance.cash_income.list' => 'Income received, as an append-only ledger of cash entries. An entry_type payment entry is a payment that was received (succeeded, refunded or disputed; never a pending, failed or canceled one) for its whole amount_minor on its received date, with its processor fee_minor where the processor has reported one, its method, its provider (null when recorded by hand) and its refunded_amount_minor so far. Money that leaves a payment later is its own signed entry bound to the same payment_id: a refund recorded by hand (refund, negative) and the reversal of one recorded in error (refund_reversal, positive, naming the refund in reverses_entry_id), or a refund, dispute or fee the processor reported (refund, refund_reversal, dispute_debit, dispute_credit, fee, fee_reversal, with fee_minor its signed expense). net_minor is amount_minor less fee_minor. effective_on is the received date for a payment, the day it was recorded in the workspace calendar for a refund recorded by hand, and the occurrence date for a processor movement. These reuse finance.invoice_payments.list and finance.processor_movements.list, which stay the way to read allocations, payouts and the processor\'s own detail. A processor refund the processor has not reported as a movement has no date and is not an entry, but still shows in the payment\'s refunded_amount_minor. A payment entry carries a version that moves when the payment is corrected or refunded; deduplicate payments by (id, version) and the rest by id. '.$feed.' A payment\'s received date can be corrected, so from and to (on effective_on) are refused beside updated_since or watermark; entry_type, client_id and invoice_id may be combined with them.',
            'finance.income_export.get' => 'One calendar year (year) of income by month, in the workspace calendar, summed from the same entries finance.invoice_lines.list and finance.cash_income.list return so it cannot disagree with them. Per month: invoiced_net_minor and invoiced_tax_minor (tax apart from the net) and invoiced_total_minor, of which expenses_passed_through_minor is the net of expense lines billed at cost; cash_received_minor; refunds_minor (refunds net of their reversals, negative); disputes_minor; fees_minor (the processor\'s signed expense); and net_cash_minor (received plus refunds plus disputes less fees). Corrections, voids and refunds count in the month they were recorded, not in the month of the invoice or payment they reverse. The same figures are totalled for the year. The assumptions block says how entries are placed and the method version. Returns JSON; the REST route finance.income_export.csv streams the same figures as CSV.',
            'finance.income_export.csv' => 'The figures of finance.income_export.get for one calendar year (year) as a CSV download: a header row, one row per month and a total row, every amount an integer in minor units. Computed in the request and streamed.',
        ];
        $parameters = [
            'entry_type' => ['name' => 'entry_type', 'in' => 'query', 'description' => 'Only entries of this type.', 'schema' => ['type' => 'string']],
            'client_id' => ['name' => 'client_id', 'in' => 'query', 'description' => 'One client of the workspace.', 'schema' => ['type' => 'string', 'format' => 'uuid']],
            'invoice_id' => ['name' => 'invoice_id', 'in' => 'query', 'description' => 'One invoice of the workspace.', 'schema' => ['type' => 'string', 'format' => 'uuid']],
            'from' => ['name' => 'from', 'in' => 'query', 'description' => 'Only entries effective on or after this date.', 'schema' => ['type' => 'string', 'format' => 'date']],
            'to' => ['name' => 'to', 'in' => 'query', 'description' => 'Only entries effective on or before this date.', 'schema' => ['type' => 'string', 'format' => 'date']],
            'per_page' => ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => AccountingFeedQuery::MAX_PER_PAGE, 'default' => AccountingFeedQuery::DEFAULT_PER_PAGE]],
            'year' => ['name' => 'year', 'in' => 'query', 'required' => true, 'description' => 'The calendar year.', 'schema' => ['type' => 'integer', 'minimum' => self::YEAR_MIN, 'maximum' => self::YEAR_MAX]],
        ];
        $entryTypes = [
            'finance.invoice_lines.list' => LineEntryType::values(),
            'finance.cash_income.list' => CashEntryType::values(),
        ];
        $errors = [
            401 => 'Not signed in, or the token is missing, expired or revoked.',
            403 => 'The token lacks finance:read, or the caller is not a workspace owner or admin.',
            404 => 'The workspace does not exist or the caller is not in it, or client_id names no client of this workspace.',
            422 => 'A parameter is invalid (`errors` names it): an unknown parameter or entry type, a malformed date, to before from, per_page out of range, a watermark in the future, a date filter beside updated_since where it is refused. Also a cursor from another workspace, query or watermark.',
            429 => 'Too many requests (retry after Retry-After).',
        ];

        $result = [];
        foreach (self::ROUTES as $id => $route) {
            $definition = self::DEFINITIONS[$id] ?? null;
            $query = $definition[3] ?? ['year'];
            $result[$id] = [
                'method' => 'GET',
                'scopes' => ['finance:read'],
                'summary' => $definition[1] ?? 'Download a calendar year of income as CSV',
                'description' => $descriptions[$id].' '.$shared,
                'parameters' => ['WorkspaceId', ...array_map(
                    static fn (string $name): string|array => isset($parameters[$name])
                        ? ($name === 'entry_type' ? [...$parameters[$name], 'schema' => ['type' => 'string', 'enum' => $entryTypes[$id] ?? []]] : $parameters[$name])
                        : $name,
                    $query,
                )],
                'errors' => $errors,
            ] + ($definition === null
                ? ['binary' => ['text/csv', 'A CSV file with one row per month and a total row']]
                : ['response' => $definition[2], 'response_description' => $definition[1]]);
        }

        return $result;
    }
}
