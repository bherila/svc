@php
    use App\Support\Billing\Document\InvoiceDocumentFormat as F;
    /** @var \App\Support\Billing\Document\InvoiceDocument $document */
    $stamp = $document->mode->stamp();
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $document->mode->heading() }} {{ $document->identity() }}</title>
    {{--
        Dompdf prints this, so the layout is tables, inline-block and fixed
        positioning only: no flexbox, no grid. The paper is always white with
        dark ink; the browser's dark mode never reaches the PDF.
    --}}
    <style>
        /* US Letter, 8.5 x 11 in, stated here as well as on the renderer so the
           document and the paper agree. The bottom margin holds the footer. */
        @page { size: 8.5in 11in; margin: 0.8in 0.75in 0.85in 0.75in; }
        body { font-family: Inter, DejaVu Sans, sans-serif; color: #1f2937; font-size: 10.5px; line-height: 1.35; margin: 0; background: #ffffff; }
        /* Repeated on every page. Fixed elements are placed inside the page
           margins, so they never overlap the content. */
        .running-header { position: fixed; top: -0.5in; left: 0; right: 0; height: 0.3in; font-size: 9px; color: #6b7280; border-bottom: 1px solid #e5e7eb; }
        .running-footer { position: fixed; bottom: -0.55in; left: 0; right: 0; height: 0.3in; font-size: 9px; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 4px; }
        /* Fixed widths, so a long unbroken invoice number or client name wraps
           inside its own cell instead of widening the table and pushing the
           administrator label off the page. */
        .running-header table, .running-footer table { width: 100%; border-collapse: collapse; margin: 0; table-layout: fixed; }
        .running-header td, .running-footer td { border: 0; padding: 0; background: none; }
        .running-header .identity { width: 62%; } .running-header .audience { width: 38%; padding-left: 8px; }
        /* Fixed chrome: at most two lines, clipped, so a very long identity
           cannot grow down into the page body either. The full number and
           name are on the first page. */
        .running-header .clip { height: 26px; overflow: hidden; }
        /* Somewhere for an unbroken identifier or name to break. */
        .wrap { word-wrap: break-word; overflow-wrap: anywhere; }
        .stamp { border: 2px solid #b45309; color: #b45309; font-weight: bold; text-align: center; padding: 6px 8px; margin: 0 0 12px; letter-spacing: 1px; }
        .masthead { width: 100%; border-collapse: collapse; border-bottom: 2px solid #111827; margin: 0 0 14px; table-layout: fixed; }
        .masthead td { border: 0; padding: 0 0 12px; vertical-align: bottom; }
        .masthead .left { width: 62%; } .masthead .right-cell { width: 38%; }
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
        /* One table for every section; a small section is kept whole so its
           heading is never left alone at the foot of a page. */
        table.sections { margin-top: 14px; }
        table.sections tbody.section.small { page-break-inside: avoid; }
        table.sections tr.section-title th { background: #ffffff; font-size: 12px; padding: 14px 0 4px; border-bottom: 2px solid #111827; }
        table.sections th.column { font-size: 9px; color: #4b5563; text-transform: uppercase; }
        table.sections tr.section-subtotal td { font-weight: bold; border-bottom: 0; }
        .summary { margin-left: auto; width: 280px; margin-top: 14px; }
        .summary td { border: 0; padding: 4px 8px; } .total { font-weight: bold; border-top: 2px solid #111827; }
        .muted { color: #6b7280; }
        .notice { border: 1px solid #d1d5db; background: #f9fafb; padding: 6px 8px; }
        .pay { margin-top: 14px; font-weight: bold; }
        /* The statement and the appendix each start a page of their own: the
           invoice is the document being paid, and what explains it should not
           push the total onto a second page. */
        .new-page { page-break-before: always; }
        .statement table { margin-top: 4px; font-size: 10.5px; page-break-inside: avoid; }
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
        <td class="identity wrap"><div class="clip">{{ $document->mode->heading() }} {{ $document->identity() }} · {{ $document->billTo }}</div></td>
        <td class="audience right">{{ $document->banner() }}</td>
    </tr></table>
</div>
<div class="running-footer">
    <table><tr>
        <td>Issue date: {{ $document->issueDate }} · {{ $document->currency }}</td>
        {{-- The page number is written onto each page after layout; see DompdfInvoicePdfRenderer::numberPages(). --}}
    </tr></table>
</div>

@if ($stamp !== null)
<div class="stamp">{{ $stamp }}</div>
@endif
<table class="masthead"><tr>
    <td class="left"><h1>{{ $document->mode->heading() }}</h1><div class="muted wrap">{{ $document->invoiceNumber }}</div>@if ($document->issuerName !== '')<div class="muted wrap issuer">From {{ $document->issuerName }}</div>@endif</td>
    <td class="right right-cell"><strong>{{ $document->statusLabel() }}</strong><br>{{ $document->currency }}</td>
</tr></table>
<p class="wrap"><strong>Bill to:</strong> {{ $document->billTo }}</p>
<p class="muted">Issue date: {{ $document->issueDate }} &nbsp; Due: {{ $document->dueDate }}</p>
@if ($document->reissueNotice() !== null)
<p class="muted">{{ $document->reissueNotice() }}</p>
@endif
<p class="muted">Service period: {{ $document->servicePeriodStart }} – {{ $document->servicePeriodEnd }}</p>
@if ($document->voidNotice() !== null)
<p class="notice voided">{{ $document->voidNotice() }}</p>
@endif
@if ($stamp !== null)
<p class="notice proforma-note">This is a preview of an invoice that has not been issued. It has no number, cannot be paid, and will change as work is logged and approved.</p>
@endif

{{-- One table for every section, so the columns line up from heading to
     heading. Each section is its own tbody, kept whole when it is small so a
     heading is never left alone at the foot of a page. --}}
<table class="sections">
    <thead>
        <tr><th class="column">Description</th><th class="column nowrap">Type</th><th class="column right">Quantity</th><th class="column right">Unit</th><th class="column right">Tax</th><th class="column right">Total</th></tr>
    </thead>
@foreach ($document->sections as $block)
    <tbody class="section {{ count($block->lines) <= 6 ? 'small' : '' }}">
        <tr class="section-title"><th colspan="6">{{ $block->section->title() }}</th></tr>
    @foreach ($block->lines as $line)
        <tr><td class="wrap">{{ $line->description }}</td><td class="nowrap">{{ $line->typeLabel() }}</td><td class="right">{{ $line->quantity }}</td><td class="right">{{ F::money($line->unitAmount) }}</td><td class="right">{{ F::money($line->taxAmount) }}</td><td class="right">{{ F::money($line->totalAmount) }}</td></tr>
    @endforeach
    @if (count($block->lines) > 1)
        <tr class="section-subtotal"><td colspan="5">{{ $block->section->title() }} subtotal</td><td class="right">{{ F::money($block->subtotal()) }}</td></tr>
    @endif
    </tbody>
@endforeach
</table>
<table class="summary">
    <tr><td>Subtotal</td><td class="right">{{ F::money($document->subtotal) }}</td></tr>
    <tr><td>Tax</td><td class="right">{{ F::money($document->tax) }}</td></tr>
    <tr class="total"><td>Total</td><td class="right">{{ F::money($document->total) }}</td></tr>
    <tr><td>Paid</td><td class="right">{{ F::money($document->paid) }}</td></tr>
    <tr><td>Balance</td><td class="right">{{ F::money($document->balance) }}</td></tr>
</table>
@if ($document->payUrl !== null)
<p class="pay wrap">Pay online: {{ $document->payUrl }}</p>
@endif
@if ($document->autopayNotice !== null)
<p class="pay wrap">{{ $document->autopayNotice }}</p>
@endif
{{-- Never print the invoice's internal notes here: they are internal-only
     (#[Hidden] on the model, suppressed on every JSON path) and this document
     is served to portal clients. --}}

{{-- The hours statement: what the retainer pool held, what this invoice drew
     on it, what was billed on top and why, and what carries forward. Every
     figure is the snapshot the generator stored with the lines (see
     InvoiceHoursStatement); nothing here recomputes it. Absent for invoices
     that sell no retainer and for those generated before it existed. --}}
@if ($document->statement !== [])
    <div class="statement new-page">
        <h2>Hours statement</h2>
        <p class="muted">{{ $document->statementIntro }}</p>
        @foreach ($document->statement as $section)
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

@if ($document->deferredWork !== null)
    <div class="appendix new-page">
        <h2>Still deferred</h2>
        <p class="muted">{{ F::entries($document->deferredWork['entry_count']) }}
            · {{ number_format($document->deferredWork['total_hours'], 4) }} hours
            · oldest worked date {{ $document->deferredWork['oldest_worked_on'] }}</p>
        <table>
            <thead><tr><th class="date">Date</th><th>Work</th><th class="hours">Hours</th></tr></thead>
            <tbody>
            @foreach ($document->deferredWork['entries'] as $item)
                <tr>
                    <td class="date">{{ $item['worked_on'] }}</td>
                    <td class="wrap">{{ $item['description'] ?? '' }}</td>
                    <td class="hours">{{ number_format($item['hours'], 4) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif

{{-- The appendix: the time entries behind each line.
     What each audience may read is decided in InvoiceLineDetail, not here: a
     client never reads an internal description. A retainer sold for the coming
     cycle is a charge, not a record of hours, and has nothing to itemise. --}}
@if ($document->appendix !== [])
    <div class="appendix new-page">
        <h2>Appendix: time entries on this invoice</h2>
        <p class="muted">Grouped by the invoice line each entry was billed on.
            @if ($document->genericLabelUsed)
                Entries shown as “{{ \App\Support\Billing\InvoiceLineDetail::CLIENT_GENERIC_LABEL }}” are billed work without a client-facing description.
            @endif
        </p>
        @foreach ($document->appendix as $block)
            <h3 class="wrap">{{ $block['description'] }}</h3>
            <table>
                <thead><tr><th class="date">Date</th><th class="project">Project</th><th>Work</th><th class="hours">Hours</th></tr></thead>
                <tbody>
                @foreach ($block['entries'] as $item)
                    <tr>
                        <td class="date">{{ $item['worked_on'] }}</td>
                        <td class="project wrap">{{ $item['project'] ?? '—' }}</td>
                        <td class="wrap">{{ $item['description'] }}</td>
                        <td class="hours">{{ F::minutesAsHours($item['minutes']) }}</td>
                    </tr>
                @endforeach
                <tr class="subtotal"><td colspan="3">{{ F::entries(count($block['entries'])) }}</td><td class="hours">{{ F::minutesAsHours($block['minutes']) }}</td></tr>
                </tbody>
            </table>
        @endforeach
    </div>
@endif
</body>
</html>
