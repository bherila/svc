<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Payment reminder: invoice {{ $invoice->invoice_number }}</title></head><body>
<h1>Payment reminder</h1>
<p>{{ $companyName }},</p>
{{-- The policy chose the link: Square's own page for an invoice Square
     collects, otherwise the client portal or nothing, with the PDF attached. --}}
<p>This is a reminder that invoice {{ $invoice->invoice_number }}@if (filled($squareInvoiceNumber)) (Square invoice {{ $squareInvoiceNumber }})@endif has a remaining balance of {{ $balance }}@if (filled($dueDate)), {{ $overdue ? 'which was due on' : 'due on' }} {{ $dueDate }}@endif.</p>
@if ($linkKind === 'square' && filled($payUrl))
<p><a href="{{ $payUrl }}">View and pay the invoice in Square</a></p>
@elseif ($linkKind === 'svc_portal' && filled($payUrl))
<p><a href="{{ $payUrl }}">View the invoice in your client portal</a></p>
<p>The invoice is attached.</p>
@else
<p>The invoice is attached.</p>
@endif
<p>If you have already paid, thank you, and please disregard this reminder.</p>
</body></html>
