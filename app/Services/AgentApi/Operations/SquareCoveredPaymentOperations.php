<?php

namespace App\Services\AgentApi\Operations;

final class SquareCoveredPaymentOperations
{
    private const string ROOT = '/workspaces/{workspace}/invoices/{invoice}/square-coverage/{squareInvoice}/payments';

    public const array ROUTES = [
        'invoices.square_covered_payments' => ['invoices.square-covered-payments.index', self::ROOT, [200]],
        'invoices.square_covered_payment_record' => ['invoices.square-covered-payments.store', self::ROOT.'/{payment}', [200]],
    ];

    /** @return array<string,array{method:string,scopes:list<string>,description:string,parameters:list<string|array<string,mixed>>,response:string,request?:string,errors:array<int,string>}> */
    public static function documentation(): array
    {
        $parameters = ['WorkspaceId', 'InvoiceId', ['name' => 'squareInvoice', 'in' => 'path', 'required' => true,
            'schema' => ['type' => 'string', 'maxLength' => 191, 'pattern' => '^[A-Za-z0-9][A-Za-z0-9:_-]{0,190}$']]];
        $description = 'Workspace managers only. Review or explicitly record one payment received by an actively covered Square invoice. This never automatically allocates covered payments. Reads the current Square invoice, order, up to 100 payments and up to 10 dispute pages before any ledger transaction; incomplete or malformed facts are refused. Each review or confirmation consumes one of 20 requests per workspace per ten minutes. Refunded or disputed unrecorded payments, over-balance amounts, and invoices with in-flight payments remain manual review cases. Nothing is written to Square.';
        $errors = [401 => 'Authentication is missing, expired or revoked.', 403 => 'Current scopes or workspace manager role are insufficient.',
            404 => 'The workspace, invoice or active coverage is unavailable, or payment writers are disabled.',
            409 => 'The invoice, coverage or reviewed payment facts changed, or an idempotency key was reused with different input.',
            422 => 'A field or explicit confirmation is invalid, or current facts cannot be recorded safely.',
            429 => 'The request budget is exhausted; retry after Retry-After.', 502 => 'Square returned malformed or incomplete current facts.', 503 => 'Square is unavailable or not connected to this workspace.'];

        return [
            'invoices.square_covered_payments' => ['method' => 'GET', 'scopes' => ['billing:read'],
                'description' => $description.' Returns current amounts, captured dates, recorded identifiers, refusal reasons and finished record URLs; a null record_href means recording is unavailable.',
                'parameters' => $parameters, 'response' => 'SquareCoveredPaymentReviewResponse', 'errors' => $errors],
            'invoices.square_covered_payment_record' => ['method' => 'POST', 'scopes' => ['billing:read', 'payments:record'],
                'description' => $description.' Requires confirm: true, the reviewed invoice expected_version and the selected payment reviewed_version. Current facts and active coverage are checked again under the invoice lock; the full payment is recorded once using its canonical Square provider identity, with no splitting or overpayment credit. A completed idempotency receipt may replay after the coverage is removed or Square becomes unavailable.',
                'parameters' => [...$parameters, ['name' => 'payment', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'maxLength' => 191, 'pattern' => '^[A-Za-z0-9][A-Za-z0-9:_-]{0,190}$']], 'IdempotencyKey'],
                'request' => 'SquareCoveredPaymentRecordRequest', 'response' => 'SquareCoveredPaymentRecordResponse', 'errors' => $errors],
        ];
    }
}
