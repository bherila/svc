<?php

namespace App\Support\Billing;

use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;

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
 * sees only entries carrying a `client_visible_description`, and sees that text
 * rather than the internal one. This is the rule the portal's time sheet and
 * client home already follow, and it has to hold here too: the invoice PDF is
 * served to portal users, so an appendix built for an operator and handed to a
 * client would publish every internal note behind a bill.
 *
 * Withheld rather than blanked. An entry with no client-visible description is
 * absent from the client's appendix entirely, so there is no row saying work
 * happened that the client is not being told about - which reads worse than
 * saying nothing.
 */
final class InvoiceLineDetail
{
    public const OPERATOR = 'operator';

    public const CLIENT = 'client';

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

        $lines->load(['timeEntries' => function ($relation) use ($invoice, $forClient): void {
            // Workspace-scoped even through the pivot. The pivot carries a
            // workspace id of its own and the entries table is written by a
            // different slice, so a row migrated in from before the composite
            // keys can name an entry of another tenant.
            $relation->where('client_time_entries.workspace_id', $invoice->workspace_id)
                ->when($forClient, fn ($query) => $query
                    ->where('is_visible_to_client', true)
                    ->whereNotNull('client_visible_description'))
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
                ? $entry->client_visible_description
                : $entry->description;

            // The client's query already filters these out, and this says so a
            // second time where the value is actually read. A cast to string
            // was the alternative and the wrong one: it would turn "we have
            // nothing to tell this client about this work" into a row with an
            // empty description, which is the one outcome this class exists to
            // avoid.
            if ($description === null) {
                continue;
            }

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
}
