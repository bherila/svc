<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        /* US Letter, 8.5 x 11 in, stated here as well as on the renderer so the
           document and the paper agree. The bottom margin holds the footer. */
        @page { size: 8.5in 11in; margin: 0.8in 0.75in 0.85in 0.75in; }
        body { font-family: DejaVu Sans, sans-serif; color: #1f2937; font-size: 11px; margin: 0; }
        /* Repeated on every page. Fixed elements are placed inside the page
           margins, so they never overlap the content. */
        .running-header { position: fixed; top: -0.5in; left: 0; right: 0; height: 0.3in; font-size: 9px; color: #6b7280; border-bottom: 1px solid #e5e7eb; }
        .running-footer { position: fixed; bottom: -0.55in; left: 0; right: 0; height: 0.3in; font-size: 9px; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 4px; }
        .running-header table, .running-footer table { width: 100%; border-collapse: collapse; margin: 0; }
        .running-header td, .running-footer td { border: 0; padding: 0; background: none; }
        .masthead { width: 100%; border-collapse: collapse; border-bottom: 2px solid #111827; margin: 0 0 14px; }
        .masthead td { border: 0; padding: 0 0 12px; vertical-align: bottom; }
        h1 { margin: 0; font-size: 24px; }
        h2 { margin: 0 0 4px; font-size: 16px; }
        h3 { margin: 16px 0 4px; font-size: 12px; font-weight: bold; }
        p { margin: 6px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 18px; }
        th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #d1d5db; vertical-align: top; }
        th { background: #f3f4f6; }
        /* A table that crosses a page repeats its header row, and a row is never
           cut in half across the break. */
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .right { text-align: right; } .nowrap { white-space: nowrap; }
        .summary { margin-left: auto; width: 280px; margin-top: 14px; }
        .summary td { border: 0; padding: 4px 8px; } .total { font-weight: bold; border-top: 2px solid #111827; }
        .muted { color: #6b7280; }
        /* The statement and the appendix each start a page of their own: the
           invoice is the document being paid, and what explains it should not
           push the total onto a second page. */
        .new-page { page-break-before: always; }
        .statement table { margin-top: 4px; font-size: 11px; page-break-inside: avoid; }
        .statement td { padding: 4px 8px; }
        .statement .detail td.label { padding-left: 24px; color: #4b5563; }
        .statement .total td { font-weight: bold; border-top: 1px solid #111827; border-bottom: 1px solid #111827; }
        .statement .note td { color: #6b7280; font-style: italic; }
        .statement .hours { width: 90px; text-align: right; white-space: nowrap; }
        .appendix table { margin-top: 4px; font-size: 10px; }
        .appendix td, .appendix th { padding: 4px 8px; }
        .appendix .subtotal td { font-weight: bold; border-top: 1px solid #111827; }
        .appendix .date { width: 70px; white-space: nowrap; }
        .appendix .project { width: 120px; }
        .appendix .hours { width: 50px; text-align: right; white-space: nowrap; }
    </style>
</head>
<body>
<div class="running-header">
    <table><tr>
        <td>Invoice {{ $invoice->invoice_number }} · {{ $invoice->clientCompany->name }}</td>
        <td class="right">{{ $audience === \App\Support\Billing\InvoiceLineDetail::OPERATOR ? 'Administrator copy: includes internal descriptions' : '' }}</td>
    </tr></table>
</div>
<div class="running-footer">
    <table><tr>
        <td>Issue date: {{ optional($invoice->issue_date)->format('Y-m-d') }} · {{ $invoice->currency }}</td>
        {{-- The page number is written onto each page after layout; see InvoiceDocumentService::numberPages(). --}}
    </tr></table>
</div>

<table class="masthead"><tr>
    <td><h1>Invoice</h1><div class="muted">{{ $invoice->invoice_number }}</div></td>
    <td class="right"><strong>{{ $statusLabel }}</strong><br>{{ $invoice->currency }}</td>
</tr></table>
<p><strong>Bill to:</strong> {{ $invoice->clientCompany->name }}</p>
<p class="muted">Issue date: {{ optional($invoice->issue_date)->format('Y-m-d') }} &nbsp; Due: {{ optional($invoice->due_date)->format('Y-m-d') }}</p>
<p class="muted">Service period: {{ optional($invoice->service_period_start)->format('Y-m-d') }} – {{ optional($invoice->service_period_end)->format('Y-m-d') }}</p>
<table>
    <thead><tr><th>Description</th><th>Type</th><th class="right">Quantity</th><th class="right">Unit</th><th class="right">Tax</th><th class="right">Total</th></tr></thead>
    <tbody>
    @foreach ($lines as $line)
        <tr><td>{{ $line->description }}</td><td>{{ $lineTypeLabels[$line->public_id] }}</td><td class="right">{{ $line->quantity }}</td><td class="right">{{ number_format($line->unit_amount / 100, 2) }}</td><td class="right">{{ number_format($line->tax_amount / 100, 2) }}</td><td class="right">{{ number_format($line->total_amount / 100, 2) }}</td></tr>
    @endforeach
    </tbody>
</table>
<table class="summary">
    <tr><td>Subtotal</td><td class="right">{{ number_format($invoice->subtotal_amount / 100, 2) }}</td></tr>
    <tr><td>Tax</td><td class="right">{{ number_format($invoice->tax_amount / 100, 2) }}</td></tr>
    <tr class="total"><td>Total</td><td class="right">{{ number_format($invoice->total_amount / 100, 2) }}</td></tr>
    <tr><td>Paid</td><td class="right">{{ number_format($invoice->paid_amount / 100, 2) }}</td></tr>
    <tr><td>Balance</td><td class="right">{{ number_format($invoice->balance_amount / 100, 2) }}</td></tr>
</table>
{{-- Never render $invoice->notes here: it is internal-only (#[Hidden] on the model,
     suppressed on every JSON path) and this template is served to portal clients. --}}

{{-- The hours statement: what the retainer pool held, what this invoice drew
     on it, what was billed on top and why, and what carries forward. Every
     figure is the snapshot the generator stored with the lines (see
     InvoiceHoursStatement); nothing here recomputes it. Absent for invoices
     that sell no retainer and for those generated before it existed. --}}
@if (! empty($statement))
    <div class="statement new-page">
        <h2>Hours statement</h2>
        <p class="muted">For the work this invoice reconciles and the retainer period that follows it. All figures are hours.</p>
        @foreach ($statement as $section)
            <h3>{{ $section['title'] }}</h3>
            <table>
                <tbody>
                @foreach ($section['rows'] as $row)
                    <tr class="{{ $row['kind'] }}"><td class="label">{{ $row['label'] }}</td><td class="hours">{{ $row['hours'] }}</td></tr>
                @endforeach
                </tbody>
            </table>
        @endforeach
    </div>
@endif

{{-- The appendix: the time entries behind each line.
     `$detail` is keyed by line public id and holds only lines with work behind
     them; a retainer sold for the coming cycle is a charge, not a record of
     hours, and has nothing to itemise. What each audience may read is decided
     in InvoiceLineDetail, not here: a client never reads an internal
     description. --}}
@if (! empty($detail))
    <div class="appendix new-page">
        <h2>Appendix: time entries on this invoice</h2>
        <p class="muted">Grouped by the invoice line each entry was billed on.
            @if ($genericLabelUsed)
                Entries shown as “{{ \App\Support\Billing\InvoiceLineDetail::CLIENT_GENERIC_LABEL }}” are billed work without a client-facing description.
            @endif
        </p>
        @foreach ($lines as $line)
            @php($items = $detail[$line->public_id] ?? [])
            @if (! empty($items))
                <h3>{{ $line->description }}</h3>
                <table>
                    <thead><tr><th class="date">Date</th><th class="project">Project</th><th>Work</th><th class="hours">Hours</th></tr></thead>
                    <tbody>
                    @foreach ($items as $item)
                        <tr>
                            <td class="date">{{ $item['worked_on'] }}</td>
                            <td class="project">{{ $item['project'] ?? '—' }}</td>
                            <td>{{ $item['description'] }}</td>
                            <td class="hours">{{ number_format($item['minutes'] / 60, 2) }}</td>
                        </tr>
                    @endforeach
                    <tr class="subtotal"><td colspan="3">{{ count($items) }} {{ count($items) === 1 ? 'entry' : 'entries' }}</td><td class="hours">{{ number_format(array_sum(array_column($items, 'minutes')) / 60, 2) }}</td></tr>
                    </tbody>
                </table>
            @endif
        @endforeach
    </div>
@endif
</body>
</html>
