<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Invoices</title></head><body>
<h1>Invoices for {{ $workspace->name }}</h1>
<ul>@foreach ($invoices as $invoice)<li><a href="{{ route('svc.billing.invoices.show', [$workspace, $invoice]) }}">{{ $invoice->invoice_number }}</a> — {{ $invoice->status }}</li>@endforeach</ul>
</body></html>
