<?php

namespace Tests\Support\Billing;

use App\Models\ClientInvoice;

/**
 * The facts an invoice document states, read out of a rendering of it.
 *
 * The golden tests compare these facts and never markup, so a second renderer
 * (a rebuilt document, a native PDF layout) is held to the same figures by
 * giving the test class another implementation of this interface and nothing
 * else. Today's single implementation reads the Blade HTML:
 * {@see BladeInvoiceDocumentFacts}.
 *
 * Every text value is whitespace-normalised and printed exactly as the reader
 * sees it ("12.50", "-1.50", "August 2026"), never as the stored number.
 *
 * @phpstan-type Row array{label: string, value: string, kind: string}
 * @phpstan-type Section array{title: string, rows: list<Row>}
 * @phpstan-type Line array{description: string, type: string, quantity: string, unit: string, tax: string, total: string}
 * @phpstan-type Facts array{
 *     invoice_number: string,
 *     status: string,
 *     currency: string,
 *     bill_to: string,
 *     issue_date: string,
 *     due_date: string,
 *     service_period: string,
 *     audience_banner: string,
 *     reissue_notice: string|null,
 *     lines: list<Line>,
 *     totals: array<string, string>,
 *     hours_statement: array{intro: string, sections: list<Section>}|null,
 *     still_deferred: array{summary: string, entries: list<array{date: string, work: string, hours: string}>}|null,
 *     appendix: array{notice: string, lines: list<array{description: string, entries: list<array{date: string, project: string, work: string, hours: string}>, subtotal: array{label: string, hours: string}}>}|null,
 * }
 */
interface InvoiceDocumentFacts
{
    /**
     * @param  'operator'|'client'  $audience
     * @return Facts
     */
    public function extract(ClientInvoice $invoice, string $audience): array;
}
