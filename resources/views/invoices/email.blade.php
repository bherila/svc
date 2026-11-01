<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Invoice {{ $invoice->invoice_number }}</title></head><body>
<h1>Invoice {{ $invoice->invoice_number }}</h1><p>{{ $invoice->clientCompany->name }},</p>
@if ($invoice->reissued_at !== null)
{{-- Reissued under the number the client already holds (#594): without this it reads as a duplicate. --}}
<p>This invoice was reissued on {{ $invoice->reissued_at->format('Y-m-d') }} under the same invoice number, {{ $invoice->invoice_number }}. It replaces the version you received earlier; please disregard that one.</p>
@endif
{{-- The operator's own covering note, when they wrote one. Escaped, and
     rendered as separate paragraphs so the line breaks they typed survive. --}}
@if (filled($note))
    @foreach (preg_split('/\R{2,}/', trim($note)) as $paragraph)
        <p>{!! nl2br(e($paragraph)) !!}</p>
    @endforeach
@endif
<p>Your invoice total is {{ $invoice->currency }} {{ number_format($invoice->total_amount / 100, 2) }} and the remaining balance is {{ $invoice->currency }} {{ number_format($invoice->balance_amount / 100, 2) }}.</p>
<p>Thank you.</p>
</body></html>
