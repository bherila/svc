<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #1f2937; margin: 40px; }
        header { display: flex; justify-content: space-between; border-bottom: 2px solid #111827; padding-bottom: 18px; }
        h1 { margin: 0; } table { width: 100%; border-collapse: collapse; margin-top: 28px; }
        th, td { text-align: left; padding: 9px; border-bottom: 1px solid #d1d5db; } th { background: #f3f4f6; }
        .right { text-align: right; } .summary { margin-left: auto; width: 280px; margin-top: 18px; }
        .summary td { border: 0; } .total { font-weight: bold; border-top: 2px solid #111827; }
        .muted { color: #6b7280; }
        /* The appendix starts a page of its own: the invoice is the document
           being paid, and the evidence behind it should not push the total
           onto a second page. */
        .appendix { page-break-before: always; }
        .appendix h2 { margin: 0 0 6px; font-size: 16px; }
        .appendix h3 { margin: 18px 0 0; font-size: 13px; font-weight: bold; }
        .appendix table { margin-top: 6px; font-size: 11px; }
        .appendix td, .appendix th { padding: 5px 9px; }
    </style>
</head>
<body>
<header>
    <div><h1>Invoice</h1><div class="muted">{{ $invoice->invoice_number }}</div></div>
    <div class="right"><strong>{{ $statusLabel }}</strong><br>{{ $invoice->currency }}</div>
</header>
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

{{-- The appendix: what each line was billed from.
     On its own page, after the invoice, because the invoice is the document
     being paid and this is the evidence behind it - a reader who wants the
     total should not have to page past three hundred time entries to find it.
     `$detail` is keyed by line public id and holds only lines with work behind
     them; a retainer sold for the coming cycle is a charge, not a record of
     hours, and has nothing to itemise. What each audience may read is decided
     in InvoiceLineDetail, not here. --}}
@if (! empty($detail))
    <div class="appendix">
        <h2>Appendix: what this covers</h2>
        @foreach ($lines as $line)
            @php($items = $detail[$line->public_id] ?? [])
            @if (! empty($items))
                <h3>{{ $line->description }}</h3>
                <table>
                    <thead><tr><th>Date</th><th>Project</th><th>Work</th><th class="right">Hours</th></tr></thead>
                    <tbody>
                    @foreach ($items as $item)
                        <tr>
                            <td>{{ $item['worked_on'] }}</td>
                            <td>{{ $item['project'] ?? '—' }}</td>
                            <td>{{ $item['description'] }}</td>
                            <td class="right">{{ number_format($item['minutes'] / 60, 2) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        @endforeach
    </div>
@endif
</body>
</html>
