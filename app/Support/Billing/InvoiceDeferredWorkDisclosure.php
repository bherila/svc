<?php

namespace App\Support\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientInvoice;

/**
 * Who may read an invoice's "Still deferred" list.
 *
 * The list itemises dates, hours and client-visible descriptions of work that
 * was done but not billed. The agreement's "Show deferred backlog to client"
 * setting (off by default) decides whether a client sees that backlog at all,
 * so a client copy of the document - portal page, PDF, email attachment -
 * follows the same setting as the live portal panel. The setting is read as it
 * stands now, not as it stood at issue: turning it off withdraws the list from
 * documents already sent. An invoice with no agreement shows clients nothing.
 * The operator copy always shows the list. The hours statement's backlog
 * figures are totals, not this list, and are unaffected.
 */
final class InvoiceDeferredWorkDisclosure
{
    /**
     * @param  InvoiceLineDetail::OPERATOR|InvoiceLineDetail::CLIENT  $audience
     * @return array{entry_count: int, total_hours: float, oldest_worked_on: string, entries: list<array{worked_on: string, hours: float, description: string|null}>}|null
     */
    public static function documentData(ClientInvoice $invoice, string $audience): ?array
    {
        return self::forAudience(
            $invoice->deferredWorkSnapshot(),
            $audience,
            static fn (): bool => self::clientMayRead($invoice),
        );
    }

    /**
     * The decision itself, free of the database so it is unit-tested directly.
     * `$clientOptedIn` is consulted only for a client copy of a stored snapshot.
     *
     * @param  InvoiceLineDetail::OPERATOR|InvoiceLineDetail::CLIENT  $audience
     * @param  callable(): bool  $clientOptedIn
     * @return array{entry_count: int, total_hours: float, oldest_worked_on: string, entries: list<array{worked_on: string, hours: float, description: string|null}>}|null
     */
    public static function forAudience(?InvoiceDeferredWorkSnapshot $snapshot, string $audience, callable $clientOptedIn): ?array
    {
        if ($snapshot === null) {
            return null;
        }

        $visible = match ($audience) {
            InvoiceLineDetail::OPERATOR => true,
            InvoiceLineDetail::CLIENT => $clientOptedIn(),
        };

        return $visible ? $snapshot->documentData() : null;
    }

    private static function clientMayRead(ClientInvoice $invoice): bool
    {
        if ($invoice->client_agreement_id === null) {
            return false;
        }

        return ClientAgreement::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('client_company_id', $invoice->client_company_id)
            ->whereKey($invoice->client_agreement_id)
            ->where('show_deferred_backlog_to_client', true)
            ->exists();
    }
}
