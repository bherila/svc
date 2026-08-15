<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Invoice {{ $invoice->invoice_number }}</title></head><body>
<h1>Invoice {{ $invoice->invoice_number }}</h1><p>{{ $invoice->clientCompany->name }},</p>
<p>Your invoice total is {{ $invoice->currency }} {{ number_format($invoice->total_amount / 100, 2) }} and the remaining balance is {{ $invoice->currency }} {{ number_format($invoice->balance_amount / 100, 2) }}.</p>
<p>Thank you.</p>
</body></html>
