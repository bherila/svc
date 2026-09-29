<?php

namespace App\Support\Billing;

use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientTimeEntry;

/**
 * What is inside an invoice line.
 *
 * A line reads "Deferred work items applied to retainer (12.50 hrs)" or
 * "Additional hours (3.00 hrs @ 225.00 USD/hr)", and a client looking at either
 * has one question the invoice does not answer: which work. The pivot has
 * carried the answer since the billing workflow was written - every line the
 * engine bills from time attaches the entries it drew on, which is also what
 * stops the same work being billed twice - and nothing ever showed it.
 *
 * ## Two audiences, and the difference is not cosmetic
 *
 * An operator sees every attached entry and its internal description. A client
 * never sees an internal description: an entry that is visible to the client
 * and carries a `client_visible_description` prints that text, and every other
 * entry prints {@see self::CLIENT_GENERIC_LABEL}. This is the wording rule the
 * portal's time sheet and client home already follow, and it has to hold here
 * too: the invoice PDF is served to portal users, so an appendix built for an
 * operator and handed to a client would publish every internal note behind a
 * bill.
 *
 * Labelled rather than withheld. An earlier version left such entries out of
 * the client's appendix entirely, on the view that a row saying work happened
 * without saying what reads worse than nothing. That stopped holding once the
 * invoice carried an hours statement: every one of these entries is billed -
 * it is on a line the client pays - and an appendix that silently drops some
 * of them totals fewer hours than the statement and the line above it, which
 * reads as an error in the bill. The date, the project and the hours are the
 * client's to know; only the operator's wording is not.
 */
final class InvoiceLineDetail
{
    public const OPERATOR = 'operator';

    public const CLIENT = 'client';

    /**
     * What a client reads for billed work that has no wording written for them.
     *
     * Neutral on purpose: it says what kind of thing the hours were without
     * implying anything about the work, and it is the same for every such entry
     * so it cannot leak by varying.
     */
    public const CLIENT_GENERIC_LABEL = 'Professional services';

    /**
     * The work behind each line of an invoice, keyed by line public id.
     *
     * One query for the whole invoice rather than one per line: an invoice with
     * forty lines is ordinary, and a lazy relation read per line is the N+1 that
     * makes a PDF time out.
     *
     * @param  self::OPERATOR|self::CLIENT  $audience
     * @return array<string, list<array{worked_on: string, project: string|null, description: string, minutes: int}>>
     */
    public static function forInvoice(ClientInvoice $invoice, string $audience): array
    {
        // Always read here, workspace-scoped, rather than reusing a relation a
        // caller may already have loaded. That branch existed to save a query
        // and quietly made the appendix's scoping depend on which caller got
        // here first: `ClientDirectoryController` loads lines constrained to
        // the workspace and `InvoiceDocumentService` does not, so the PDF was
        // itemised from a set nothing had bounded. One query is a cheap price
        // for the read being scoped the same way every time.
        $lines = $invoice->lines()->where('workspace_id', $invoice->workspace_id)->get();

        $forClient = $audience === self::CLIENT;

        $lines->load(['timeEntries' => function ($relation) use ($invoice): void {
            // Workspace-scoped even through the pivot. The pivot carries a
            // workspace id of its own and the entries table is written by a
            // different slice, so a row migrated in from before the composite
            // keys can name an entry of another tenant.
            $relation->where('client_time_entries.workspace_id', $invoice->workspace_id)
                ->with(['project' => fn ($project) => $project->where('workspace_id', $invoice->workspace_id)])
                ->orderBy('worked_on')
                ->orderBy('id');
        }]);

        $detail = [];

        foreach ($lines as $line) {
            $items = self::itemsOf($line, $forClient);

            if ($items !== []) {
                $detail[$line->public_id] = $items;
            }
        }

        return $detail;
    }

    /**
     * @return list<array{worked_on: string, project: string|null, description: string, minutes: int}>
     */
    private static function itemsOf(ClientInvoiceLine $line, bool $forClient): array
    {
        $items = [];

        foreach ($line->timeEntries as $entry) {
            $description = $forClient
                ? self::clientWording($entry)
                : (string) $entry->description;

            $items[] = [
                'worked_on' => $entry->worked_on->toDateString(),
                // Nullable despite the column being NOT NULL, and no test can
                // reach it: `client_time_entries` carries composite tenant keys
                // on both its company and its project, so the schema refuses to
                // write an entry naming another workspace's project, and the
                // constrained eager load below always finds one. Rows migrated
                // in from before those keys can still hold the mismatch, and
                // for one of those the difference is a project name from
                // another tenant printed on this invoice's appendix, or a fatal
                // on `->name` while rendering a client's PDF. Degrading to
                // "not named" is the right end of that, and it cannot be
                // asserted from a test that has to create the row first.
                //
                // @infection-ignore-all
                'project' => $entry->project?->name,
                'description' => $description,
                'minutes' => $entry->minutes,
            ];
        }

        return $items;
    }

    /**
     * The client's wording for one entry: theirs when it was written for them,
     * the generic label otherwise, and never the internal description.
     */
    private static function clientWording(ClientTimeEntry $entry): string
    {
        $written = $entry->client_visible_description;

        return $entry->is_visible_to_client === true && is_string($written) && trim($written) !== ''
            ? $written
            : self::CLIENT_GENERIC_LABEL;
    }
}
