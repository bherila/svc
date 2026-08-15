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
    </style>
</head>
<body>
<header>
    <div><h1>Invoice</h1><div class="muted">{{ $invoice->invoice_number }}</div></div>
    <div class="right"><strong>{{ strtoupper($invoice->status) }}</strong><br>{{ $invoice->currency }}</div>
</header>
<p><strong>Bill to:</strong> {{ $invoice->clientCompany->name }}</p>
<p class="muted">Issue date: {{ optional($invoice->issue_date)->format('Y-m-d') }} &nbsp; Due: {{ optional($invoice->due_date)->format('Y-m-d') }}</p>
<p class="muted">Service period: {{ optional($invoice->service_period_start)->format('Y-m-d') }} – {{ optional($invoice->service_period_end)->format('Y-m-d') }}</p>
<table>
    <thead><tr><th>Description</th><th>Type</th><th class="right">Quantity</th><th class="right">Unit</th><th class="right">Tax</th><th class="right">Total</th></tr></thead>
    <tbody>
    @foreach ($invoice->lines as $line)
        <tr><td>{{ $line->description }}</td><td>{{ $line->type }}</td><td class="right">{{ $line->quantity }}</td><td class="right">{{ number_format($line->unit_amount / 100, 2) }}</td><td class="right">{{ number_format($line->tax_amount / 100, 2) }}</td><td class="right">{{ number_format($line->total_amount / 100, 2) }}</td></tr>
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
@if ($invoice->notes)<p><strong>Notes:</strong> {{ $invoice->notes }}</p>@endif
</body>
</html>
